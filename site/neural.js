
(function(){
  const canvas = document.createElement('canvas');
  canvas.id = 'neural-bg';
  canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;pointer-events:none;opacity:0.55;';
  document.body.prepend(canvas);

  const ctx = canvas.getContext('2d');
  let W, H, nodes=[], animId;
  const NODE_COUNT = 80;
  const MAX_DIST = 160;

  function resize(){
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }

  function Node(){
    this.x = Math.random()*W;
    this.y = Math.random()*H;
    this.vx = (Math.random()-.5)*0.4;
    this.vy = (Math.random()-.5)*0.4;
    this.r = Math.random()*2+1;
    this.pulse = Math.random()*Math.PI*2;
    this.type = Math.random()>.7?'star':'node';
  }

  function init(){
    nodes=[];
    for(let i=0;i<NODE_COUNT;i++) nodes.push(new Node());
  }

  function draw(){
    ctx.clearRect(0,0,W,H);

    // Galaxy core glow
    const cx=W*.5, cy=H*.4;
    const grd=ctx.createRadialGradient(cx,cy,0,cx,cy,Math.min(W,H)*.45);
    grd.addColorStop(0,'rgba(0,80,160,0.18)');
    grd.addColorStop(.4,'rgba(0,30,80,0.10)');
    grd.addColorStop(1,'rgba(0,0,0,0)');
    ctx.fillStyle=grd;
    ctx.fillRect(0,0,W,H);

    const t=Date.now()*.001;

    // Draw edges (neural connections)
    for(let i=0;i<nodes.length;i++){
      for(let j=i+1;j<nodes.length;j++){
        const dx=nodes[i].x-nodes[j].x;
        const dy=nodes[i].y-nodes[j].y;
        const d=Math.sqrt(dx*dx+dy*dy);
        if(d<MAX_DIST){
          const alpha=(1-d/MAX_DIST)*0.35;
          ctx.beginPath();
          ctx.strokeStyle=`rgba(0,180,255,${alpha})`;
          ctx.lineWidth=0.6;
          ctx.moveTo(nodes[i].x,nodes[i].y);
          ctx.lineTo(nodes[j].x,nodes[j].y);
          ctx.stroke();

          // Data pulse along edge
          if(Math.random()<0.003){
            const prog=(t*0.5)%1;
            const px=nodes[i].x+dx*prog;
            const py=nodes[i].y+dy*prog;
            ctx.beginPath();
            ctx.arc(px,py,1.5,0,Math.PI*2);
            ctx.fillStyle='rgba(0,255,180,0.9)';
            ctx.fill();
          }
        }
      }
    }

    // Draw nodes
    nodes.forEach(n=>{
      n.pulse+=0.03;
      const pulse=Math.sin(n.pulse)*.5+.5;

      if(n.type==='star'){
        // Star sparkle
        const size=n.r*(1+pulse*.5);
        ctx.beginPath();
        ctx.arc(n.x,n.y,size,0,Math.PI*2);
        ctx.fillStyle=`rgba(200,230,255,${0.6+pulse*.4})`;
        ctx.fill();
        // glow
        const sg=ctx.createRadialGradient(n.x,n.y,0,n.x,n.y,size*4);
        sg.addColorStop(0,`rgba(150,220,255,${0.3*pulse})`);
        sg.addColorStop(1,'rgba(0,0,0,0)');
        ctx.fillStyle=sg;
        ctx.beginPath();
        ctx.arc(n.x,n.y,size*4,0,Math.PI*2);
        ctx.fill();
      } else {
        // Neural node
        ctx.beginPath();
        ctx.arc(n.x,n.y,n.r,0,Math.PI*2);
        ctx.fillStyle=`rgba(0,${160+pulse*80},255,${0.7+pulse*.3})`;
        ctx.fill();
        // ring
        ctx.beginPath();
        ctx.arc(n.x,n.y,n.r+2+pulse*2,0,Math.PI*2);
        ctx.strokeStyle=`rgba(0,200,255,${0.15*pulse})`;
        ctx.lineWidth=1;
        ctx.stroke();
      }

      // Move
      n.x+=n.vx; n.y+=n.vy;
      if(n.x<-20) n.x=W+20;
      if(n.x>W+20) n.x=-20;
      if(n.y<-20) n.y=H+20;
      if(n.y>H+20) n.y=-20;
    });

    // Floating hex grid overlay (subtle)
    ctx.strokeStyle='rgba(0,100,180,0.04)';
    ctx.lineWidth=1;
    const hex=60;
    for(let x=-hex;x<W+hex;x+=hex*1.5){
      for(let y=-hex;y<H+hex;y+=hex*Math.sqrt(3)){
        const ox=((y/hex)%2)*hex*.75;
        drawHex(ctx,x+ox,y,hex*.48);
      }
    }

    animId=requestAnimationFrame(draw);
  }

  function drawHex(ctx,x,y,r){
    ctx.beginPath();
    for(let i=0;i<6;i++){
      const a=Math.PI/3*i-Math.PI/6;
      i===0?ctx.moveTo(x+r*Math.cos(a),y+r*Math.sin(a)):ctx.lineTo(x+r*Math.cos(a),y+r*Math.sin(a));
    }
    ctx.closePath();ctx.stroke();
  }

  window.addEventListener('resize',()=>{resize();init();});
  resize();init();draw();
})();
