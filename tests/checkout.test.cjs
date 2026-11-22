const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync('modules/gateways/pesepaydirect/assets/checkout.js', 'utf8');
const markup = execFileSync('php', ['-r', "define('WHMCS',true); $_SESSION=[]; require 'modules/gateways/pesepaydirect.php'; echo pesepaydirect_link(['systemurl'=>'https://billing.example.test/','invoiceid'=>42]);"], {encoding:'utf8'});
const tick = () => new Promise(resolve => setImmediate(resolve));
async function setup(handler) {
  const dom = new JSDOM(markup, {url:'https://billing.example.test/viewinvoice.php?id=42',runScripts:'outside-only'});
  const calls=[];
  dom.window.fetch = async (url,opts) => {
    const body=Object.fromEntries(new URLSearchParams(opts.body)); calls.push(body);
    const result=await handler(body,calls);
    return {ok:!result.error,json:async()=>result};
  };
  dom.window.eval(source); await tick();
  return {dom,calls,get:name=>dom.window.document.querySelector('.ppd-'+name)};
}
const methods=[{code:'PZW211',name:'EcoCash',phone:'ecocash',message:'Approve on your phone.'}];
const pending={id:1,paid:false,terminal:false,retryable:false,status:'PENDING',method:'PZW211',reference:'REF-1',metadata:{}};
test('page load never initiates a payment; double click creates one request',async()=>{
  let resolveStart;
  const app=await setup(body=>body.action==='load'?{methods,attempt:null}:new Promise(r=>resolveStart=r));
  assert.deepEqual(app.calls.map(x=>x.action),['load']);
  app.get('phone').value='0777777777'; app.get('pay').click(); app.get('pay').click();
  assert.equal(app.calls.filter(x=>x.action==='start').length,1);
  resolveStart({attempt:pending}); await tick();
  assert.equal(app.get('options').hidden,true); assert.equal(app.get('retry').hidden,true);
  app.dom.window.close();
});
test('lost response recovery reuses request identity',async()=>{
  let count=0;
  const app=await setup(body=>{
    if(body.action==='load') return {methods,attempt:null};
    if(++count===1) throw new Error('Connection lost');
    return {attempt:pending};
  });
  app.get('pay').click(); await tick(); app.get('pay').click(); await tick();
  const starts=app.calls.filter(x=>x.action==='start'); assert.equal(starts.length,2); assert.equal(starts[0].requestKey,starts[1].requestKey);
  app.dom.window.close();
});
test('payment box is hidden when the invoice is already paid',async()=>{
  const app=await setup(()=>({methods:[],attempt:{...pending,paid:true,terminal:true,status:'SUCCESS'}}));
  assert.equal(app.dom.window.document.querySelector('[data-pesepay-direct]').hidden,true);
  app.dom.window.close();
});
test('pending payment polls automatically behind a spinner, with no status button',async()=>{
  const app=await setup(()=>({methods,attempt:pending}));
  assert.equal(app.get('check'),null); assert.equal(app.get('loader').hidden,false);
  app.dom.window.close();
});
test('request without a reference keeps polling behind the spinner, since the callback can supply it',async()=>{
  const app=await setup(()=>({methods,attempt:{...pending,status:'UNKNOWN',reference:null}}));
  assert.match(app.get('status').textContent,/Confirming your payment/); assert.equal(app.get('retry').hidden,true); assert.equal(app.get('loader').hidden,false);
  app.dom.window.close();
});
test('rejected request says nothing was charged and offers a retry',async()=>{
  const app=await setup(body=>body.action==='load'?{methods,attempt:null}:{attempt:{...pending,status:'REJECTED',reference:null,terminal:true,retryable:true}});
  app.get('phone').value='0777777777'; app.get('pay').click(); await tick();
  assert.match(app.get('status').textContent,/Nothing was charged/); assert.equal(app.get('retry').hidden,false); assert.equal(app.get('loader').hidden,true);
  app.dom.window.close();
});
test('provider metadata is rendered as text and rejects script URLs',async()=>{
  const app=await setup(()=>({methods,attempt:{...pending,method:'PZW210',metadata:{qrCode:'<img src=x onerror=alert(1)>',qrCodeUrl:'javascript:alert(1)'}}}));
  assert.equal(app.get('code').children.length,0); assert.equal(app.get('qr').hidden,true); app.dom.window.close();
});
test('uncertain outcome hides retry and OTP is sent only once',async()=>{
  const app=await setup(body=>body.action==='load'?{methods,attempt:{...pending,method:'PZW216',needsOtp:true}}:{error:'Connection interrupted'});
  app.get('otp-value').value='123456'; app.get('submit-otp').click(); await tick(); app.get('submit-otp').click();
  assert.equal(app.calls.filter(x=>x.action==='otp').length,1); assert.equal(app.get('otp-value').value,''); app.dom.window.close();
});
test('reloading after a retryable failure shows the payment form, not the old error',async()=>{
  const app=await setup(()=>({methods,attempt:{...pending,status:'REJECTED',reference:null,terminal:true,retryable:true}}));
  assert.equal(app.get('options').hidden,false); assert.match(app.get('status').textContent,/nothing was charged. You can pay now/);
  app.dom.window.close();
});
test('test mode is announced at checkout and on a confirmed payment',async()=>{
  let app=await setup(()=>({methods,attempt:null,test:true}));
  assert.match(app.get('status').textContent,/TEST MODE/); app.dom.window.close();
  let n=0;
  app=await setup(body=>body.action==='load'?{methods,attempt:null,test:true}:(++n===1?{attempt:{...pending,test:true}}:{attempt:{...pending,paid:true,terminal:true,status:'SUCCESS',test:true}}));
  app.get('phone').value='0777777777'; app.get('pay').click(); await tick();
  await new Promise(r=>setTimeout(r,5300)); await tick();
  assert.match(app.get('status').textContent,/Test payment confirmed/); app.dom.window.close();
});
test('a payment confirmed on this page reloads the invoice once; an already-paid invoice does not',async()=>{
  let n=0;
  const app=await setup(body=>body.action==='load'?{methods,attempt:null}:(++n===1?{attempt:pending}:{attempt:{...pending,paid:true,terminal:true,status:'SUCCESS'}}));
  app.get('phone').value='0777777777'; app.get('pay').click(); await tick();
  assert.match(app.get('status').textContent,/Approve the payment/);
  await new Promise(r=>setTimeout(r,5300)); await tick();
  assert.match(app.get('status').textContent,/Refreshing your invoice/);
  app.dom.window.close();
  const paid=await setup(()=>({methods:[],attempt:{...pending,paid:true,terminal:true,status:'SUCCESS'}}));
  assert.doesNotMatch(paid.get('status').textContent,/Refreshing/); assert.equal(paid.dom.window.document.querySelector('[data-pesepay-direct]').hidden,true); paid.dom.window.close();
});
test('single wallet is stated, amount shows on the box and button, bank-transfer hint is visible',async()=>{
  const app=await setup(()=>({methods,attempt:null,amount:'1.00',currency:'USD'}));
  assert.equal(app.get('wallets').hidden,true); assert.equal(app.get('single').textContent,'Paying with EcoCash');
  assert.equal(app.get('amount-value').textContent,'USD 1.00'); assert.equal(app.get('pay').textContent,'Pay USD 1.00');
  assert.equal(app.get('alt').hidden,false); assert.match(app.get('alt').textContent,/bank transfer/);
  assert.equal(app.dom.window.document.querySelector('select'),null,'no dropdown');
  app.dom.window.close();
});
test('several wallets become tiles and the chosen one is sent; hint hides while waiting',async()=>{
  const many=[...methods,{code:'PZW216',name:'O’mari',phone:'omari',message:''},{code:'PZW210',name:'PayGo',phone:'',message:''}];
  const app=await setup(body=>body.action==='load'?{methods:many,attempt:null}:{attempt:pending});
  const tiles=app.dom.window.document.querySelectorAll('.ppd-wallet input');
  assert.equal(tiles.length,3); assert.equal(app.get('wallets').hidden,false); assert.equal(tiles[0].checked,true);
  tiles[2].checked=true; tiles[2].dispatchEvent(new app.dom.window.Event('change',{bubbles:true}));
  assert.equal(app.get('phone-label').hidden,true,'PayGo needs no phone');
  app.get('pay').click(); await tick();
  assert.equal(app.calls.find(x=>x.action==='start').method,'PZW210'); assert.equal(app.get('alt').hidden,true);
  app.dom.window.close();
});
