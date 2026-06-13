const puppeteer=require('puppeteer');
(async()=>{
  const b=await puppeteer.launch({executablePath:'/home/claude/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome',headless:'new',args:['--no-sandbox','--disable-setuid-sandbox']});
  const p=await b.newPage();
  await p.emulate({viewport:{width:430,height:900,isMobile:true,hasTouch:true},userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605'});
  await p.goto('file:///home/claude/prev_app.html',{waitUntil:'networkidle2',timeout:20000}).catch(_=>{});
  await new Promise(r=>setTimeout(r,1200));
  // 1) open side
  await p.evaluate(()=>toggleSide(true));
  await new Promise(r=>setTimeout(r,300));
  const opened=await p.evaluate(()=>document.getElementById('side').classList.contains('open'));
  await p.screenshot({path:'/home/claude/swipe_open.png'});
  // 2) simulate swipe-left on side
  await p.evaluate(()=>{
    const el=document.getElementById('side');
    function mk(type,x,y){
      const t=new Touch({identifier:1,target:el,clientX:x,clientY:y});
      return new TouchEvent(type,{cancelable:true,bubbles:true,touches:type==='touchend'?[]:[t],targetTouches:type==='touchend'?[]:[t],changedTouches:[t]});
    }
    el.dispatchEvent(mk('touchstart',200,400));
    el.dispatchEvent(mk('touchmove',120,403));
    el.dispatchEvent(mk('touchmove',40,406));
    el.dispatchEvent(mk('touchmove',10,408));
    el.dispatchEvent(mk('touchend',10,408));
  });
  await new Promise(r=>setTimeout(r,400));
  const closed=await p.evaluate(()=>!document.getElementById('side').classList.contains('open'));
  await p.screenshot({path:'/home/claude/swipe_after.png'});
  // 3) vertical swipe should NOT close
  await p.evaluate(()=>toggleSide(true));
  await new Promise(r=>setTimeout(r,250));
  await p.evaluate(()=>{
    const el=document.getElementById('side');
    function mk(type,x,y){const t=new Touch({identifier:2,target:el,clientX:x,clientY:y});return new TouchEvent(type,{cancelable:true,bubbles:true,touches:type==='touchend'?[]:[t],targetTouches:type==='touchend'?[]:[t],changedTouches:[t]});}
    el.dispatchEvent(mk('touchstart',120,300));
    el.dispatchEvent(mk('touchmove',122,200));
    el.dispatchEvent(mk('touchmove',121,120));
    el.dispatchEvent(mk('touchend',121,120));
  });
  await new Promise(r=>setTimeout(r,300));
  const stillOpenAfterVertical=await p.evaluate(()=>document.getElementById('side').classList.contains('open'));
  await b.close();
  console.log(JSON.stringify({opened,closedAfterSwipeLeft:closed,stillOpenAfterVerticalScroll:stillOpenAfterVertical}));
})();
