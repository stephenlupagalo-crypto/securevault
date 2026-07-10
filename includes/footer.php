  </div><!-- /page-body -->
</div><!-- /main -->

<script>
// Mobile menu
const mm=document.getElementById('mbMenu');
if(mm){mm.style.display='block'}

// Global file search (filters table rows if present)
function liveSearch(q){
  const rows=document.querySelectorAll('.file-row');
  if(!rows.length)return;
  const lq=q.toLowerCase();
  rows.forEach(r=>{
    r.style.display=r.textContent.toLowerCase().includes(lq)?'':'none';
  });
}
</script>
</body>
</html>
