// Moon Lander. All tuning values live in CFG.
const CFG={W:800,H:600,N:160,G:40,UP:95,SIDE:55,BURN_UP:14,BURN_SIDE:7,MAX_VY:40,MAX_VX:25,STEP:1/60};
const {W,H,N,G,UP,SIDE}=CFG,DX=W/N;
const cv=document.getElementById('c'),ctx=cv.getContext('2d'),msg=document.getElementById('msg');
const inp={u:0,l:0,r:0};
let hs,pad,rocks,ship,level=1,score=0,state='ready',best=0,stars=[],parts=[];
try{best=+localStorage.getItem('ml-best')||0}catch(e){}

function rng(a){return()=>{a|=0;a=a+0x6D2B79F5|0;let t=Math.imul(a^a>>>15,1|a);t=t+Math.imul(t^t>>>7,61|t)^t;return((t^t>>>14)>>>0)/4294967296}}
function fit(){const s=Math.min(innerWidth/W,innerHeight/H),d=devicePixelRatio||1;
 cv.style.width=W*s+'px';cv.style.height=H*s+'px';cv.width=W*s*d;cv.height=H*s*d;ctx.setTransform(s*d,0,0,s*d,0,0)}
addEventListener('resize',fit);fit();

function groundY(x){const f=Math.max(0,Math.min(N,x/DX)),i=Math.min(N-1,Math.floor(f));return hs[i]+(hs[i+1]-hs[i])*(f-i)}

function gen(lv){
 const r=rng(lv*7919+13),P=[r()*6,r()*6,r()*6];hs=[];
 const padW=Math.max(60,120-Math.floor(lv/2)*20),pc=140+r()*(W-280);
 const base=x=>470+26*Math.sin(x*.006+P[0])+14*Math.sin(x*.016+P[1])+6*Math.sin(x*.045+P[2]); // rolling hills
 const cr=[];
 for(let k=0,t=0;k<2+Math.ceil(lv/2)&&t<60;t++){const rad=35+r()*50,cx=40+r()*(W-80);
  if(Math.abs(cx-pc)>rad*1.4+padW/2+15){cr.push({cx,rad});k++}}   // craters never touch the pad
 for(let i=0;i<=N;i++){const x=i*DX;let h=base(x);
  for(const c of cr){const d=Math.abs(x-c.cx),dp=c.rad*.32;
   if(d<c.rad)h+=dp*(d*d/(c.rad*c.rad)-1);                       // bowl
   h+=dp*.35*Math.exp(-(((d-c.rad)/(c.rad*.22))**2))}            // raised rim
  hs.push(Math.max(380,Math.min(565,h)))}
 const p0=Math.round((pc-padW/2)/DX),p1=Math.round((pc+padW/2)/DX),py=hs[Math.round(pc/DX)];
 for(let i=p0;i<=p1;i++)hs[i]=py;                                // flat landing pad
 pad={x0:p0*DX,x1:p1*DX,y:py};
 rocks=[];
 for(let k=0;k<3+lv*2;k++){const x=30+r()*(W-60);
  if(x>pad.x0-25&&x<pad.x1+25)continue;
  const rad=7+r()*10;rocks.push({x,y:groundY(x)-rad*.4,r:rad})}
 stars=Array.from({length:70},()=>[r()*W,r()*330,r()*1.5+.5]);
 ship={x:100+r()*600,y:50,vx:(r()-.5)*40,vy:0,fuel:Math.max(50,110-lv*6),fl:{},tilt:0};
}

function end(win,why){
 state=win?'won':'lost';if(!win)burst();
 if(win){score+=Math.round(ship.fuel*10);if(level>best){best=level;try{localStorage.setItem('ml-best',best)}catch(e){}}}
 show(win?`<b>Landed!</b>Score ${score}\nTap or press Space for level ${level+1}`:`<b>Crashed</b>${why}\nTap or press Space to retry`);
}
function show(h){msg.innerHTML=h;msg.style.display='flex'}
function go(){
 if(state==='won')level++;
 if(state==='lost'){score=0;}
 gen(level);parts=[];state='play';msg.style.display='none';
}

function step(dt){
 const s=ship;let ax=0,ay=G;s.fl={};
 if(s.fuel>0){
  if(inp.u){ay-=UP;s.fuel-=CFG.BURN_UP*dt;s.fl.u=1}
  if(inp.l){ax-=SIDE;s.fuel-=CFG.BURN_SIDE*dt;s.fl.l=1}
  if(inp.r){ax+=SIDE;s.fuel-=CFG.BURN_SIDE*dt;s.fl.r=1}
 }
 s.fuel=Math.max(0,s.fuel);
 s.tilt+=((s.fuel>0?(inp.r-inp.l)*.18:0)-s.tilt)*Math.min(1,5*dt);
 if(s.fl.u)for(let i=0;i<2;i++)parts.push({x:s.x+(Math.random()-.5)*4,y:s.y+12,vx:s.vx*.5+(Math.random()-.5)*30,vy:s.vy+90+Math.random()*60,life:.5,max:.5,c:'250,180,60'});
 s.vx+=ax*dt;s.vy+=ay*dt;s.x+=s.vx*dt;s.y+=s.vy*dt;
 if(s.x<8||s.x>W-8)return end(false,'You flew off the map.');
 const pts=[[s.x,s.y-10],[s.x,s.y+4],[s.x-8,s.y+12],[s.x+8,s.y+12]];
 for(const k of rocks)for(const [px,py] of pts)
  if(Math.hypot(px-k.x,py-k.y)<k.r)return end(false,'You hit a rock.');
 const b=s.y+12,fl=s.x-8,fr=s.x+8;
 if(b>=groundY(fl)||b>=groundY(fr)||b>=groundY(s.x)){
  if(!(fl>=pad.x0&&fr<=pad.x1))return end(false,'Missed the landing pad.');
  if(Math.abs(s.vy)>CFG.MAX_VY)return end(false,'Too fast vertically.');
  if(Math.abs(s.vx)>CFG.MAX_VX)return end(false,'Too much sideways speed.');
  s.y=pad.y-12;end(true);
 }
}

function burst(){for(let i=0;i<40;i++){const a=Math.random()*6.28,v=40+Math.random()*120;
 parts.push({x:ship.x,y:ship.y,vx:Math.cos(a)*v,vy:Math.sin(a)*v-40,life:1,max:1,c:i%2?'255,140,40':'170,170,170'})}}
function upd(dt){for(const p of parts){p.vy+=G*dt;p.x+=p.vx*dt;p.y+=p.vy*dt;p.life-=dt}
 parts=parts.filter(p=>p.life>0&&p.y<groundY(p.x))}

function rocket(s){
 ctx.save();ctx.translate(s.x,s.y);ctx.rotate(s.tilt);
 if(s.fl.u){const L=18+Math.random()*10;
  let g=ctx.createLinearGradient(0,10,0,10+L);g.addColorStop(0,'#fff');g.addColorStop(.3,'#fd5');g.addColorStop(1,'rgba(255,100,0,0)');
  ctx.fillStyle=g;ctx.beginPath();ctx.moveTo(-4,10);ctx.lineTo(4,10);ctx.lineTo(0,10+L);ctx.fill()}
 ctx.fillStyle='#fd8';
 if(s.fl.l){ctx.fillRect(6,-3,7+Math.random()*4,2)}
 if(s.fl.r){ctx.fillRect(-13-Math.random()*4,-3,7+Math.random()*4,2)}
 ctx.strokeStyle='#bbb';ctx.lineWidth=1.5;ctx.beginPath();            // landing legs
 ctx.moveTo(-5,5);ctx.lineTo(-9,12);ctx.moveTo(5,5);ctx.lineTo(9,12);
 ctx.moveTo(-11,12);ctx.lineTo(-7,12);ctx.moveTo(7,12);ctx.lineTo(11,12);ctx.stroke();
 ctx.fillStyle='#555';ctx.beginPath();ctx.moveTo(-3,6);ctx.lineTo(3,6);ctx.lineTo(5,10);ctx.lineTo(-5,10);ctx.fill(); // nozzle
 ctx.fillStyle='#c33';ctx.beginPath();ctx.moveTo(-6,0);ctx.lineTo(-10,8);ctx.lineTo(-6,6);ctx.moveTo(6,0);ctx.lineTo(10,8);ctx.lineTo(6,6);ctx.fill(); // fins
 let g=ctx.createLinearGradient(-6,0,6,0);g.addColorStop(0,'#aaa');g.addColorStop(.4,'#fff');g.addColorStop(1,'#999');
 ctx.fillStyle=g;ctx.fillRect(-6,-6,12,12);                          // body
 ctx.beginPath();ctx.moveTo(-6,-6);ctx.quadraticCurveTo(0,-24,6,-6);ctx.fill(); // nose cone
 ctx.fillStyle='#c33';ctx.fillRect(-6,1,12,2);
 ctx.fillStyle='#4af';ctx.beginPath();ctx.arc(0,-4,2.6,0,7);ctx.fill();
 ctx.restore()}

function draw(){
 ctx.fillStyle='#05060f';ctx.fillRect(0,0,W,H);
 ctx.fillStyle='#fff';for(const [x,y,r] of stars)ctx.fillRect(x,y,r,r);
 let g=ctx.createRadialGradient(640,95,2,640,95,34);g.addColorStop(0,'#8cf');g.addColorStop(.6,'#38b');g.addColorStop(1,'#124');   // Earth
 ctx.fillStyle=g;ctx.beginPath();ctx.arc(640,95,34,0,7);ctx.fill();
 g=ctx.createLinearGradient(0,380,0,H);g.addColorStop(0,'#b4b4bc');g.addColorStop(1,'#34343c');
 ctx.beginPath();ctx.moveTo(0,H);for(let i=0;i<=N;i++)ctx.lineTo(i*DX,hs[i]);ctx.lineTo(W,H);ctx.closePath();
 ctx.fillStyle=g;ctx.fill();
 ctx.strokeStyle='#d8d8de';ctx.lineWidth=1.5;ctx.beginPath();for(let i=0;i<=N;i++)ctx[i?'lineTo':'moveTo'](i*DX,hs[i]);ctx.stroke();
 for(const k of rocks){ctx.fillStyle='#55555e';ctx.beginPath();ctx.arc(k.x,k.y,k.r,0,7);ctx.fill();
  ctx.fillStyle='#7a7a84';ctx.beginPath();ctx.arc(k.x-k.r*.25,k.y-k.r*.3,k.r*.5,0,7);ctx.fill()}
 ctx.strokeStyle='#3f6';ctx.lineWidth=4;ctx.beginPath();ctx.moveTo(pad.x0,pad.y-2);ctx.lineTo(pad.x1,pad.y-2);ctx.stroke();
 ctx.fillStyle='#3f6';ctx.fillRect(pad.x0-2,pad.y-14,4,12);ctx.fillRect(pad.x1-2,pad.y-14,4,12);
 const s=ship;if(state!=='lost')rocket(s);
 for(const p of parts){ctx.fillStyle=`rgba(${p.c},${p.life/p.max})`;ctx.beginPath();ctx.arc(p.x,p.y,2+(1-p.life/p.max)*2,0,7);ctx.fill()}
 ctx.fillStyle='#fff';ctx.font='14px system-ui';ctx.textAlign='left';
 const vyOk=Math.abs(s.vy)<=CFG.MAX_VY,vxOk=Math.abs(s.vx)<=CFG.MAX_VX;
 ctx.fillText(`Level ${level}   Score ${score}   Best level ${best}`,12,22);
 ctx.fillText(`Fuel ${s.fuel.toFixed(0)}`,12,42);
 ctx.fillStyle=vyOk?'#3f6':'#f55';ctx.fillText(`Vertical ${s.vy.toFixed(0)}`,12,62);
 ctx.fillStyle=vxOk?'#3f6':'#f55';ctx.fillText(`Sideways ${s.vx.toFixed(0)}`,12,82);
 ctx.fillStyle='#222';ctx.fillRect(100,32,100,10);ctx.fillStyle='#fa3';ctx.fillRect(100,32,s.fuel,10);
}

let last=0,acc=0;
function frame(t){
 const dt=Math.min(.1,(t-last)/1000||0);last=t;
 if(state==='play'){acc+=dt;while(acc>=CFG.STEP&&state==='play'){step(CFG.STEP);acc-=CFG.STEP}}
 upd(dt);draw();requestAnimationFrame(frame);
}
document.addEventListener('visibilitychange',()=>{last=performance.now();acc=0;if(document.hidden)for(const k in inp)inp[k]=0});

// Input: keyboard + touch pads
const keys={ArrowUp:'u',KeyW:'u',ArrowLeft:'l',KeyA:'l',ArrowRight:'r',KeyD:'r'};
addEventListener('keydown',e=>{
 if(keys[e.code]){inp[keys[e.code]]=1;e.preventDefault()}
 else if((e.code==='Space'||e.code==='Enter')&&state!=='play')go();
});
addEventListener('keyup',e=>{if(keys[e.code])inp[keys[e.code]]=0});
for(const k of ['u','l','r']){const el=document.getElementById(k);
 const set=v=>e=>{e.preventDefault();inp[k]=v;el.classList.toggle('on',!!v)};
 el.addEventListener('pointerdown',set(1));
 for(const ev of ['pointerup','pointercancel','pointerleave'])el.addEventListener(ev,set(0));
}
msg.addEventListener('pointerdown',()=>{if(state!=='play')go()});

gen(level);
show('<b>Moon Lander</b>Land on the green pad, away from rocks.\nKeep both speeds green.\n↑ / W thrust   ← → / A D sideways\nTap or press Space to start');
if('serviceWorker' in navigator)addEventListener('load',()=>navigator.serviceWorker.register('sw.js').catch(()=>{}));
requestAnimationFrame(frame);
