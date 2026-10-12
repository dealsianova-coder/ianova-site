</main></div>
<script>
(function(){
  // Label every table cell with its column name so tables become cards on phones.
  document.querySelectorAll('.table').forEach(function(t){
    var rows=[].slice.call(t.querySelectorAll('tr')), labels=[];
    rows.forEach(function(r){
      var ths=r.querySelectorAll('th');
      if(ths.length){ r.classList.add('th-row'); labels=[].map.call(ths,function(h){return h.textContent.trim();}); return; }
      [].forEach.call(r.children,function(c,i){
        if(c.tagName==='TD'){ if(labels[i]) c.setAttribute('data-label',labels[i]); if(i===0) c.classList.add('cell-main'); if(/action/i.test(labels[i]||'')) c.classList.add('cell-actions'); }
      });
    });
  });
})();
</script>
<script>
document.addEventListener('click',function(e){
  var t=e.target.closest('[data-zoom]'); if(!t) return;
  e.preventDefault();
  var o=document.createElement('div'); o.className='zoom-ov';
  o.innerHTML='<img src="'+t.getAttribute('data-zoom')+'" alt=""><button type="button" aria-label="Close">×</button>';
  o.addEventListener('click',function(){o.remove();});
  document.body.appendChild(o);
});
</script>
</body></html>
