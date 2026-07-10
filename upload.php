<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid   = $_SESSION['user_id'];
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'No file uploaded or an upload error occurred (error code: '.($_FILES['file']['error'] ?? 'N/A').')';
    } else {
        $file       = $_FILES['file'];
        $orig_name  = basename($file['name']);
        $size       = (int)$file['size'];
        $tmp        = $file['tmp_name'];
        $desc       = trim($_POST['description'] ?? '');

        // Validate size
        if ($size > MAX_FILE_SIZE) {
            $error = 'File is too large. Maximum size is '.format_bytes(MAX_FILE_SIZE).'.';
        } else {
            // Detect real MIME type
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($tmp);

            if (!in_array($mime, ALLOWED_TYPES)) {
                $error = 'File type "' . e($mime) . '" is not allowed.';
            } else {
                // Check quota
                $sq = db()->prepare('SELECT storage_used, storage_quota FROM users WHERE id=?');
                $sq->execute([$uid]);
                $stor = $sq->fetch();
                if ((int)$stor['storage_used'] + $size > (int)$stor['storage_quota']) {
                    $error = 'Storage quota exceeded. Free up space or request more storage.';
                } else {
                    try {
                        // Read and encrypt
                        $plaintext = file_get_contents($tmp);
                        $enc       = encrypt_file($plaintext);

                        // Store encrypted file
                        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0750, true);
                        $stored_name = bin2hex(random_bytes(16)) . '.vault';
                        file_put_contents(UPLOAD_DIR . $stored_name, $enc['cipher']);

                        $cat = file_category($mime);

                        db()->prepare('INSERT INTO files (user_id,original_name,stored_name,mime_type,file_size,file_category,encryption_iv,encryption_tag,description) VALUES (?,?,?,?,?,?,?,?,?)')
                             ->execute([$uid, $orig_name, $stored_name, $mime, $size, $cat, $enc['iv'], $enc['tag'], $desc]);

                        // Update storage
                        db()->prepare('UPDATE users SET storage_used=storage_used+? WHERE id=?')->execute([$size, $uid]);

                        log_activity('upload', "File: $orig_name ($mime, ".format_bytes($size).')');
                        add_alert($uid, 'success', 'File Uploaded', "\"$orig_name\" was encrypted and stored successfully.");

                        $_SESSION['flash'] = ['type'=>'success','msg'=>"\"$orig_name\" uploaded and encrypted successfully."];
                        redirect(BASE_PATH.'files.php');
                    } catch (Throwable $e) {
                        $error = 'Upload failed: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

$page_title = 'Upload File';
require_once __DIR__ . '/includes/header.php';
?>

<div class="section-title">Upload a File</div>
<div class="section-sub">Files are encrypted with AES-256-GCM immediately on upload.</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start">

<div class="card">
  <?php if($error): ?>
  <div class="flash flash-error" style="margin-bottom:18px">⚠️ <?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="" enctype="multipart/form-data" id="uploadForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <!-- DROP ZONE -->
    <div id="dropZone" style="
      border:2px dashed var(--border2);border-radius:16px;
      padding:48px 24px;text-align:center;cursor:pointer;
      transition:border-color .2s,background .2s;margin-bottom:20px;
      background:var(--surface2);
    "
    ondragover="ev(event,'over')" ondragleave="ev(event,'leave')" ondrop="ev(event,'drop')"
    onclick="document.getElementById('fileInput').click()">
      <div style="font-size:2.8rem;margin-bottom:12px" id="dz-icon">📤</div>
      <div style="font-size:1rem;font-weight:600;margin-bottom:6px" id="dz-label">Drag &amp; drop your file here</div>
      <div style="font-size:.82rem;color:var(--muted)">or click to browse — up to <?= format_bytes(MAX_FILE_SIZE) ?></div>
      <div id="dz-file-info" style="margin-top:12px;display:none">
        <div style="background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);padding:10px 16px;border-radius:10px;font-size:.9rem;color:#93c5fd" id="dz-name"></div>
      </div>
    </div>
    <input type="file" id="fileInput" name="file" style="display:none" onchange="fileChosen(this)">

    <div class="form-group">
      <label class="form-label">Description (optional)</label>
      <textarea name="description" class="form-control" rows="2" placeholder="Brief note about this file…"><?= e($_POST['description'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center" id="submitBtn">
      🔐 Encrypt &amp; Upload
    </button>

    <!-- Progress -->
    <div id="progress-wrap" style="display:none;margin-top:14px">
      <div style="font-size:.83rem;color:var(--muted);margin-bottom:6px" id="progress-label">Uploading…</div>
      <div style="height:6px;border-radius:3px;background:var(--surface2);overflow:hidden">
        <div id="progress-bar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--accent),var(--accent2));transition:width .2s;border-radius:3px"></div>
      </div>
    </div>
  </form>
</div>

<!-- SIDEBAR INFO -->
<div>
  <div class="card" style="margin-bottom:16px">
    <div class="card-title" style="margin-bottom:14px">Allowed File Types</div>
    <?php
    $type_groups = [
      'Documents'  => ['PDF','Word','Excel','PowerPoint','Text','CSV'],
      'Images'     => ['JPEG','PNG','GIF','WebP','SVG'],
      'Audio'      => ['MP3','WAV','OGG'],
      'Video'      => ['MP4','WebM'],
      'Archives'   => ['ZIP','RAR','7Z','GZ'],
    ];
    foreach($type_groups as $grp => $types):
    ?>
    <div style="margin-bottom:10px">
      <div style="font-size:.75rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px"><?= $grp ?></div>
      <div style="display:flex;gap:4px;flex-wrap:wrap">
        <?php foreach($types as $t): ?>
        <span class="badge badge-info"><?= $t ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card" style="background:rgba(16,185,129,.06);border-color:rgba(16,185,129,.2)">
    <div style="font-size:.88rem;color:#6ee7b7;line-height:1.7">
      🔒 <strong>End-to-end encryption</strong><br>
      Every file is encrypted with AES-256-GCM before being written to disk. The encryption key never leaves the server and is never stored in the database.
    </div>
  </div>
</div>

</div>

<script>
const dz = document.getElementById('dropZone');

function ev(e,type){
  e.preventDefault();
  if(type==='over'){dz.style.borderColor='var(--accent)';dz.style.background='rgba(59,130,246,.08)'}
  else if(type==='leave'){dz.style.borderColor='var(--border2)';dz.style.background='var(--surface2)'}
  else if(type==='drop'){
    dz.style.borderColor='var(--border2)';dz.style.background='var(--surface2)';
    const f=e.dataTransfer.files[0];
    if(f){
      const dt=new DataTransfer();dt.items.add(f);
      document.getElementById('fileInput').files=dt.files;
      showFile(f);
    }
  }
}

function fileChosen(inp){if(inp.files[0])showFile(inp.files[0])}

function showFile(f){
  document.getElementById('dz-icon').textContent='✅';
  document.getElementById('dz-label').textContent='File selected';
  const info=document.getElementById('dz-file-info');
  document.getElementById('dz-name').textContent=f.name+' ('+formatBytes(f.size)+')';
  info.style.display='block';
}

function formatBytes(b){
  if(b>=1073741824)return(b/1073741824).toFixed(2)+' GB';
  if(b>=1048576)return(b/1048576).toFixed(2)+' MB';
  if(b>=1024)return(b/1024).toFixed(2)+' KB';
  return b+' B';
}

document.getElementById('uploadForm').addEventListener('submit',function(){
  document.getElementById('submitBtn').disabled=true;
  document.getElementById('submitBtn').textContent='Encrypting & Uploading…';
  document.getElementById('progress-wrap').style.display='block';
  let p=0;
  const bar=document.getElementById('progress-bar');
  const lbl=document.getElementById('progress-label');
  const iv=setInterval(()=>{
    p=Math.min(p+Math.random()*8,90);
    bar.style.width=p+'%';
    lbl.textContent=p<40?'Encrypting file…':p<80?'Uploading…':'Finalising…';
  },200);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
