/* BlueJus · Mapa mundial por país (desenho docs/design/perfis-1.0/mapa-mundo.html), servido pelo Painel do Master.
   Portado do desenho. O navegador não chama site externo: malhas locais e municípios via /master/ibge/municipios/{UF}.
   Textos no catálogo (master.js.mapa_mundo.*, entregue pelo mapa_mundo.html.twig com catalogo_js) e lidos por BJ_T.t; os
   nomes de país (PT), de estado (NOME) e de região (REG) ficam em português (spec idioma-do-sistema §8). */
const CFG=JSON.parse(document.body.dataset.cfg||'{}');
const T=(chave,params)=>window.BJ_T.t(chave,params);
const PT={'076':'Brasil','620':'Portugal','840':'Estados Unidos','724':'Espanha','380':'Itália','250':'França','276':'Alemanha','826':'Reino Unido','032':'Argentina','600':'Paraguai','858':'Uruguai','152':'Chile','170':'Colômbia','604':'Peru','068':'Bolívia','862':'Venezuela','124':'Canadá','484':'México','036':'Austrália','392':'Japão','156':'China','024':'Angola','508':'Moçambique','132':'Cabo Verde','756':'Suíça','528':'Países Baixos','056':'Bélgica','372':'Irlanda'};
const COD={11:'RO',12:'AC',13:'AM',14:'RR',15:'PA',16:'AP',17:'TO',21:'MA',22:'PI',23:'CE',24:'RN',25:'PB',26:'PE',27:'AL',28:'SE',29:'BA',31:'MG',32:'ES',33:'RJ',35:'SP',41:'PR',42:'SC',43:'RS',50:'MS',51:'MT',52:'GO',53:'DF'};
const UFCOD=Object.fromEntries(Object.entries(COD).map(([k,v])=>[v,k]));
const NOME={RO:'Rondônia',AC:'Acre',AM:'Amazonas',RR:'Roraima',PA:'Pará',AP:'Amapá',TO:'Tocantins',MA:'Maranhão',PI:'Piauí',CE:'Ceará',RN:'Rio Grande do Norte',PB:'Paraíba',PE:'Pernambuco',AL:'Alagoas',SE:'Sergipe',BA:'Bahia',MG:'Minas Gerais',ES:'Espírito Santo',RJ:'Rio de Janeiro',SP:'São Paulo',PR:'Paraná',SC:'Santa Catarina',RS:'Rio Grande do Sul',MS:'Mato Grosso do Sul',MT:'Mato Grosso',GO:'Goiás',DF:'Distrito Federal'};
const REG={N:'Norte',NE:'Nordeste',CO:'Centro-Oeste',SE:'Sudeste',S:'Sul'},RUF={RO:'N',AC:'N',AM:'N',RR:'N',PA:'N',AP:'N',TO:'N',MA:'NE',PI:'NE',CE:'NE',RN:'NE',PB:'NE',PE:'NE',AL:'NE',SE:'NE',BA:'NE',MG:'SE',ES:'SE',RJ:'SE',SP:'SE',PR:'S',SC:'S',RS:'S',MS:'CO',MT:'CO',GO:'CO',DF:'CO'};
const escH=window.BJAgregado.escH,dados={};let UFD={},CID={},RG={};
const escala=(n,mx)=>!n?'#f7f9fb':d3.interpolateRgb('#9cc7e2','#0a4f7a')(Math.sqrt(n/Math.max(1,mx)));
let maxP=1,maxU=1;
// Contagens validadas vindas do servidor (/master/dados/mapa). Brasil = total das UFs; outros países: dado não existe ainda.
const AGREGADO=window.BJAgregado.carregar(CFG.dadosMapa).then(g=>{UFD=g.A;CID=g.C;dados['076']=Object.values(UFD).reduce((a,b)=>a+b,0);maxP=Math.max(1,...Object.values(dados));maxU=Math.max(1,...Object.values(UFD))});
const norm=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
const inv=f=>{if(d3.geoArea(f)>2*Math.PI){const g=f.geometry;if(g.type==='Polygon')g.coordinates.forEach(r=>r.reverse());else g.coordinates.forEach(p=>p.forEach(r=>r.reverse()));}};
const svg=d3.select('#m'),tip=document.getElementById('tip'),painel=document.getElementById('painel'),lista=document.getElementById('lista'),carreg=document.getElementById('carreg'),trilha=document.getElementById('trilha');
const W=innerWidth,H=innerHeight;svg.attr('viewBox','0 0 '+W+' '+H);
const zG=svg.append('g').attr('id','uZoom').append('g'),pG=zG.append('g'),ufG=zG.append('g'),munG=zG.append('g'),labG=zG.append('g').style('pointer-events','none');
let pr,path,paises,nivel=0,paisSel=null,ufSel=null,ufGeo=null,K=1,cacheMun={};
const nomeP=f=>PT[f.id]||f.properties.name;
const showTip=(e,t)=>{tip.textContent=t;tip.style.opacity=1;window.__tipE={x:e.clientX,y:e.clientY};cancelAnimationFrame(window.__tipR);window.__tipR=requestAnimationFrame(()=>{const p=window.__tipE,Wv=innerWidth,Hv=innerHeight,w=tip.offsetWidth,hh=tip.offsetHeight;let x=p.x+18,y=p.y+22;if(x+w>Wv-8)x=Math.max(8,p.x-w-14);if(y+hh>Hv-8)y=Math.max(8,p.y-hh-14);tip.style.left=x+'px';tip.style.top=y+'px'})};
const rot=(x,y,t,d)=>{const k=K,w=(t.length*7+12)/k,h=18/k,g=labG.append('g').attr('transform','translate('+x+','+y+')').style('opacity',0);g.append('rect').attr('x',-w/2).attr('y',-h/2).attr('width',w).attr('height',h).attr('rx',3/k).attr('fill','#12242f');g.append('text').attr('text-anchor','middle').attr('y',4/k).attr('fill','#fff').attr('font-size',11.5/k).attr('font-weight','bold').text(t);g.transition().delay(d||0).duration(300).style('opacity',1)};
function zoom(b,lim){const [[x0,y0],[x1,y1]]=b,k=Math.min(lim,.8/Math.max((x1-x0)/W,(y1-y0)/H));K=k;d3.select('#sombra feDropShadow').attr('dy',2/k).attr('stdDeviation',4/k);zG.transition().duration(850).ease(d3.easeCubicInOut).attr('transform','translate('+(W/2-k*(x0+x1)/2)+','+(H/2-k*(y0+y1)/2)+') scale('+k+')');return k}
function rotPaises(){labG.selectAll('*').remove();paises.filter(f=>dados[f.id]).forEach(f=>{let c=path.centroid(f);if(f.id==='840')c=pr([-98,39]);if(f.id==='620')c=pr([-8.2,39.6]);if(f.id==='250')c=pr([2.5,46.5]);rot(c[0],c[1],String(dados[f.id]))})}
function linhas(arr,tot,click){const mx=Math.max(1,...arr.map(a=>a[1])),soma=arr.reduce((a,c)=>a+c[1],0);let h=arr.map(([n,v,k])=>'<div'+(click?' class="cl" data-k="'+escH(k)+'"':'')+'><i style="width:'+(v/mx*100)+'%"></i><span>'+escH(n)+'</span><span><b>'+(+v)+'</b></span></div>').join('');if(tot>soma)h+='<div class="ins"><span>'+escH(T('master.js.mapa_mundo.local_nao_informado'))+'</span><span>'+(tot-soma)+'</span></div>';return h}
function setTrilha(){const p=['<a data-n="0">'+escH(T('master.js.mapa_mundo.mundo'))+'</a>'];if(paisSel)p.push(nivel>1?'<a data-n="1">'+escH(nomeP(paisSel))+'</a>':escH(nomeP(paisSel)));if(ufSel)p.push(escH(NOME[ufSel]));trilha.innerHTML=p.join(' › ');trilha.querySelectorAll('a').forEach(a=>a.onclick=()=>irPara(+a.dataset.n))}
function painelPais(f){const n=dados[f.id]||0;painel.classList.add('on');
  if(f.id==='076'){document.getElementById('pNome').innerHTML=escH(nomeP(f))+'<span>'+escH(T('master.js.mapa_mundo.por_estado',{n:+n}))+'</span>';const arr=Object.entries(UFD).sort((a,b)=>b[1]-a[1]).map(([u,v])=>[NOME[u]||u,v,u]);lista.innerHTML=arr.length?linhas(arr,n,true):'<div class="vazio">'+escH(T('master.js.mapa_mundo.estados_nao_informados'))+'</div>';lista.querySelectorAll('.cl').forEach(d=>d.onclick=()=>{const g=ufGeo&&ufGeo.features.find(x=>COD[+String(x.properties.codarea).slice(0,2)]===d.dataset.k);if(g)abrirUF(g)})}
  else{document.getElementById('pNome').innerHTML=escH(nomeP(f))+'<span>'+escH(n?T('master.js.mapa_mundo.por_regiao',{n:+n}):T('master.js.mapa_mundo.sem_usuarios'))+'</span>';const arr=Object.entries(RG[f.id]||{}).sort((a,b)=>b[1]-a[1]);lista.innerHTML=arr.length?linhas(arr,n):'<div class="vazio">'+escH(n?T('master.js.mapa_mundo.regioes_nao_informadas'):T('master.js.mapa_mundo.nenhum_no_pais'))+'</div>'}
  setTrilha()}
async function abrirPais(f){window.__zReset&&window.__zReset();window.__zReset&&window.__zReset();if(nivel>=1&&paisSel===f)return;nivel=1;paisSel=f;ufSel=null;tip.style.opacity=0;munG.selectAll('*').remove();ufG.selectAll('*').remove();labG.selectAll('*').remove();
  const k=zoom(path.bounds(f),f.id==='840'?4:14);
  pG.selectAll('path').attr('filter',d=>d===f?'url(#sombra)':null).style('opacity',d=>d===f?1:.12).attr('stroke-width',.5/k);pG.selectAll('path').filter(d=>d===f).raise();
  painelPais(f);
  if(f.id!=='076'){const n=dados[f.id];if(n){const c=f.id==='840'?pr([-98,39]):f.id==='620'?pr([-8.2,39.6]):path.centroid(f);rot(c[0],c[1],String(n),600)}return}
  carreg.style.opacity=1;
  try{if(!ufGeo){const r=await fetch(CFG.malhaUf);ufGeo=await r.json();ufGeo.features.forEach(inv)}
    if(paisSel!==f||nivel!==1)return;
    const uf=g=>COD[+String(g.properties.codarea).slice(0,2)];
    ufG.selectAll('path').data(ufGeo.features).join('path').attr('class','uf').attr('d',path).attr('fill',g=>UFD[uf(g)]?escala(UFD[uf(g)],maxU):'#f2f6f9').attr('stroke-width',.7/k).style('opacity',0)
      .on('mousemove',(e,g)=>{if(nivel!==1)return;const u=uf(g),n=UFD[u]||0;showTip(e,T('master.js.mapa_mundo.dica_detalhar',{nome:NOME[u],qtd:n?T('master.js.mapa_mundo.usuarios',{n}):T('master.js.mapa_mundo.sem_atuacao')}))})
      .on('mouseleave',()=>tip.style.opacity=0).on('click',(e,g)=>{e.stopPropagation();if(nivel===1)abrirUF(g);else if(ufSel!==uf(g))irPara(1)})
      .transition().delay(400).duration(500).style('opacity',1);
    pG.selectAll('path').filter(d=>d===f).transition().delay(400).duration(500).attr('fill','#f7f9fb');
    ufGeo.features.filter(g=>UFD[uf(g)]).forEach(g=>{const [x,y]=path.centroid(g);rot(x,y,String(UFD[uf(g)]),750)});
  }catch(e){lista.insertAdjacentHTML('beforeend','<div class="vazio">'+escH(T('master.js.mapa_mundo.erro_estados'))+'</div>')}
  carreg.style.opacity=0}
async function abrirUF(g){window.__zReset&&window.__zReset();window.__zReset&&window.__zReset();const u=COD[+String(g.properties.codarea).slice(0,2)];nivel=2;ufSel=u;tip.style.opacity=0;labG.selectAll('*').remove();munG.selectAll('*').remove();
  const k=zoom(path.bounds(g),90);
  ufG.selectAll('path').attr('filter',d=>d===g?'url(#sombra)':null).style('opacity',d=>d===g?1:.12).attr('stroke-width',.7/k);ufG.selectAll('path').filter(d=>d===g).raise();
  const tot=UFD[u]||0,cs=Object.entries(CID[u]||{}).sort((a,b)=>b[1]-a[1]);
  document.getElementById('pNome').innerHTML=escH(NOME[u])+'<span>'+escH(T('master.js.mapa_mundo.regiao',{regiao:REG[RUF[u]],n:+tot}))+'</span>';
  lista.innerHTML=cs.length?linhas(cs,tot):'<div class="vazio">'+escH(tot?T('master.js.mapa_mundo.cidades_nao_informadas'):T('master.js.mapa_mundo.nenhum_no_estado'))+'</div>';setTrilha();
  carreg.style.opacity=1;
  try{let m=cacheMun[u];if(!m){const c=UFCOD[u];const rr=await fetch(CFG.municipios.replace(/XX$/,u),{credentials:'same-origin',headers:{Accept:'application/json'}});if(!rr.ok||!c)throw 0;const {geo,nomes}=await rr.json();if(!geo||!geo.features)throw 0;geo.features.forEach(inv);const nm=Object.assign({},nomes||{});m=cacheMun[u]={geo,nm}}
    if(ufSel!==u||nivel!==2)return;
    const cn={};cs.forEach(([n,v])=>cn[norm(n)]=v);const mxc=Math.max(1,...cs.map(c=>c[1]));const nome=x=>m.nm[String(x.properties.codarea)]||T('master.js.mapa_mundo.municipio'),val=x=>cn[norm(nome(x))]||0;
    munG.selectAll('path').data(m.geo.features).join('path').attr('class','mun').attr('d',path).attr('stroke-width',.5/k).attr('fill',x=>val(x)?escala(val(x),mxc):'#eef4f8').style('opacity',0)
      .on('mousemove',(e,x)=>showTip(e,T('master.js.mapa_mundo.dica',{nome:nome(x),qtd:val(x)?T('master.js.mapa_mundo.usuarios',{n:val(x)}):T('master.js.mapa_mundo.sem_usuarios')}))).on('mouseleave',()=>tip.style.opacity=0)
      .transition().delay(350).duration(500).style('opacity',1);
    m.geo.features.filter(val).forEach(x=>{const [px,py]=path.centroid(x);rot(px,py,String(val(x)),650)});
    document.getElementById('pNome').innerHTML=escH(NOME[u])+'<span>'+escH(T('master.js.mapa_mundo.regiao_municipios',{regiao:REG[RUF[u]],n:+tot,m:+m.geo.features.length}))+'</span>';
  }catch(e){if(ufSel===u)lista.insertAdjacentHTML('beforeend','<div class="vazio">'+escH(T('master.js.mapa_mundo.erro_municipios'))+'</div>')}
  carreg.style.opacity=0}
function irPara(n){window.__zReset&&window.__zReset();tip.style.opacity=0;carreg.style.opacity=0;
  if(n<=0){if(!nivel)return;nivel=0;paisSel=null;ufSel=null;painel.classList.remove('on');munG.selectAll('*').remove();ufG.selectAll('path').transition().duration(250).style('opacity',0).remove();labG.selectAll('*').remove();pG.selectAll('path').interrupt().attr('filter',null).style('opacity',1).attr('stroke-width',.5).attr('fill',f=>escala(dados[f.id],maxP));K=1;zG.transition().duration(800).ease(d3.easeCubicInOut).attr('transform','translate(0,0) scale(1)').on('end',()=>{if(!nivel)rotPaises()});return}
  if(n===1&&nivel===2){const f=paisSel;nivel=0;paisSel=null;abrirPais(f)}}
document.getElementById('voltar').onclick=()=>irPara(nivel-1);
addEventListener('keydown',e=>{if(e.key==='Escape')irPara(nivel-1)});
AGREGADO.then(()=>fetch(CFG.malhaMundo))
.then(r=>{if(!r.ok)throw 0;return r.json()}).then(topo=>{
  const geo=topojson.feature(topo,topo.objects.countries);geo.features=geo.features.filter(f=>f.id!=='010');paises=geo.features;
  document.getElementById('msg').remove();
  pr=d3.geoNaturalEarth1().fitExtent([[2,2],[W-2,H-2]],geo);path=d3.geoPath(pr);
  svg.on('click',e=>{if(e.defaultPrevented)return;if(e.target===svg.node())irPara(nivel-1)});
  pG.selectAll('path').data(paises).join('path').attr('class','pais').attr('d',path).attr('fill',f=>escala(dados[f.id],maxP))
    .on('mousemove',(e,f)=>{if(nivel)return tip.style.opacity=0;const n=dados[f.id]||0;showTip(e,T('master.js.mapa_mundo.dica_detalhar',{nome:nomeP(f),qtd:n?T('master.js.mapa_mundo.usuarios',{n}):T('master.js.mapa_mundo.sem_atuacao')}))})
    .on('mouseleave',()=>tip.style.opacity=0)
    .on('click',(e,f)=>{e.stopPropagation();if(!nivel)abrirPais(f);else if(f!==paisSel)irPara(0)});
  rotPaises();
}).catch(()=>{document.getElementById('msg').textContent=T('master.js.mapa_mundo.erro_mapa')});

(()=>{const p=document.getElementById('painel'),t=p.querySelector('.top');t.style.cursor='grab';t.title=T('master.js.mapa_mundo.arraste');let sx,sy,ox,oy,on=false;
t.addEventListener('pointerdown',e=>{if(e.target.closest('button'))return;on=true;t.setPointerCapture(e.pointerId);t.style.cursor='grabbing';const r=p.getBoundingClientRect();sx=e.clientX;sy=e.clientY;ox=r.left;oy=r.top;p.style.transition='opacity .35s';e.preventDefault()});
t.addEventListener('pointermove',e=>{if(!on)return;const w=p.offsetWidth,h=p.offsetHeight;p.style.left=Math.max(0,Math.min(innerWidth-w,ox+e.clientX-sx))+'px';p.style.top=Math.max(0,Math.min(innerHeight-h,oy+e.clientY-sy))+'px'});
const fim=()=>{if(!on)return;on=false;t.style.cursor='grab';try{localStorage.setItem('bj-mapa-legenda',JSON.stringify({l:p.style.left,t:p.style.top}))}catch(e){}};
t.addEventListener('pointerup',fim);t.addEventListener('pointercancel',fim);
t.addEventListener('dblclick',e=>{if(e.target.closest('button'))return;p.style.left='10px';p.style.top='10px';localStorage.removeItem('bj-mapa-legenda')});
try{const s=JSON.parse(localStorage.getItem('bj-mapa-legenda')||'null');if(s){p.style.left=s.l;p.style.top=s.t}}catch(e){}})();

(()=>{const svg=d3.select('#m'),uG=d3.select('#uZoom');const z=d3.zoom().scaleExtent([1,12]).filter(e=>(e.type==='wheel'||e.type==='touchstart'||e.type==='mousedown'&&e.button===0||e.type==='dblclick')).on('zoom',e=>{uG.attr('transform',e.transform);document.getElementById('zRes').style.opacity=e.transform.k>1.01?1:.4});
svg.call(z).on('dblclick.zoom',null);
window.__zReset=()=>svg.transition().duration(500).call(z.transform,d3.zoomIdentity);
const b=document.createElement('div');b.id='zCtl';b.innerHTML='<button data-z="1.6" title="'+escH(T('master.js.mapa_mundo.aproximar'))+'">+</button><button data-z="0.625" title="'+escH(T('master.js.mapa_mundo.afastar'))+'">&minus;</button><button id="zRes" title="'+escH(T('master.js.mapa_mundo.ajustar'))+'" style="opacity:.4">&#10530;</button>';document.body.appendChild(b);
b.querySelectorAll('button[data-z]').forEach(x=>x.onclick=()=>svg.transition().duration(350).call(z.scaleBy,+x.dataset.z));document.getElementById('zRes').onclick=window.__zReset;})();

(()=>{const uG=document.getElementById('uZoom');let uk=1;
function aplicar(){uG.querySelectorAll('g').forEach(g=>{const r=g.querySelector(':scope>rect');if(!r||r.getAttribute('fill')!=='#12242f')return;if(g.__t==null)g.__t=(g.getAttribute('transform')||'').replace(/\s*scale\([^)]*\)/,'');g.setAttribute('transform',g.__t+(uk!==1?' scale('+(1/uk)+')':''))});
uG.querySelectorAll('path').forEach(p=>{if(p.__sw==null){const v=parseFloat(p.getAttribute('stroke-width')||getComputedStyle(p).strokeWidth);p.__sw=isNaN(v)?.5:v}p.setAttribute('stroke-width',p.__sw*(uk>1?1.8:1)/uk)})}
let nG=0;setInterval(()=>{const t=uG.getAttribute('transform')||'',m=t.match(/scale\(([\d.]+)/),k=m?+m[1]:1,n=uG.querySelectorAll('path').length;if(n!==nG){nG=n;if(k===1)uG.querySelectorAll('path').forEach(p=>p.__sw=null);uk=k;aplicar();return}if(Math.abs(k-uk)>1e-4){uk=k;aplicar()}},150);
const p=document.getElementById('painel');const prender=()=>{const w=p.offsetWidth,h=p.offsetHeight;if(!p.style.left)return;p.style.left=Math.max(0,Math.min(innerWidth-w,parseFloat(p.style.left)))+'px';p.style.top=Math.max(0,Math.min(innerHeight-h,parseFloat(p.style.top)))+'px'};
addEventListener('resize',prender);
p.querySelector('.top').addEventListener('pointermove',()=>requestAnimationFrame(prender));prender();})();
