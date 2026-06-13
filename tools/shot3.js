const puppeteer=require('puppeteer');
(async()=>{
  const b=await puppeteer.launch({executablePath:'/home/claude/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome',headless:'new',args:['--no-sandbox','--disable-setuid-sandbox']});
  const p=await b.newPage();await p.setViewport({width:1400,height:980,deviceScaleFactor:1});
  for(const [f,o] of [['prev_app','green_app'],['prev_index','green_idx'],['prev_admin','green_admin']]){
    await p.goto('file:///home/claude/'+f+'.html',{waitUntil:'networkidle2',timeout:20000}).catch(e=>{});
    await new Promise(r=>setTimeout(r,1400));
    await p.screenshot({path:'/home/claude/shot_'+o+'.png'});
  }
  await b.close();console.log('done');
})();
