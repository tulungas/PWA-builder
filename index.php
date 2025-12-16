<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

// --- LOGIC START ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    try {
        // 0. SERVER CHECK: Kya zaroori extensions installed hain?
        if (!extension_loaded('zip')) {
            throw new Exception("Server Error: <b>ZIP Extension</b> is missing on this server.");
        }
        if (!extension_loaded('gd')) {
            throw new Exception("Server Error: <b>GD Library</b> (Image processing) is missing on this server.");
        }

        // 1. Basic Validation
        if (!isset($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload failed. Code: " . $_FILES['zip']['error']);
        }

        $finfo = pathinfo($_FILES['zip']['name']);
        if(strtolower($finfo['extension']) !== 'zip'){
            throw new Exception("Only .zip files are allowed!");
        }

        // 2. Setup Variables
        $appName = preg_replace("/[^a-zA-Z0-9 ]/", "", $_POST['app_name']);
        $safeName = preg_replace("/[^a-zA-Z0-9]/", "_", $appName);
        $color = $_POST['theme_color'];
        $tempBase = sys_get_temp_dir(); 
        $uniqueId = uniqid("pwa_", true);
        $tempDir = $tempBase . DIRECTORY_SEPARATOR . $uniqueId;
        $extractPath = $tempDir . DIRECTORY_SEPARATOR . "extracted";
        
        if (!mkdir($extractPath, 0777, true)) {
            throw new Exception("Server Permission Error: Cannot write to temporary folder ($tempDir).");
        }

        // 3. Extract ZIP
        $zip = new ZipArchive;
        if ($zip->open($_FILES['zip']['tmp_name']) === TRUE) {
            $zip->extractTo($extractPath);
            $zip->close();
        } else {
            throw new Exception("Invalid or Corrupted ZIP file.");
        }

        // 4. SMART SEARCH: Find Entry File (index.html OR index.php)
        $realRoot = $extractPath;
        $entryFile = "";
        $found = false;

        // Check Root level
        if (file_exists($extractPath . "/index.html")) {
            $entryFile = "index.html";
            $found = true;
        } elseif (file_exists($extractPath . "/index.php")) {
            $entryFile = "index.php";
            $found = true;
        }

        // Check Subfolders
        if (!$found) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractPath));
            foreach ($it as $file) {
                if ($file->getFilename() === 'index.html') {
                    $realRoot = dirname($file->getPathname());
                    $entryFile = "index.html";
                    $found = true;
                    break;
                }
                if ($file->getFilename() === 'index.php' && !$found) {
                    $realRoot = dirname($file->getPathname());
                    $entryFile = "index.php";
                    $found = true;
                    break; 
                }
            }
        }

        if (!$found) {
            throw new Exception("<b>Entry file not found!</b><br>Zip must contain either index.html or index.php.");
        }

        // 5. SMART SEARCH: Find Icon
        $validExtensions = ['png', 'jpg', 'jpeg'];
        $possibleNames = ['icon', 'logo', 'app-icon', 'favicon'];
        $iconPath = "";
        $dirFiles = scandir($realRoot);

        foreach ($dirFiles as $file) {
            $info = pathinfo($file);
            if (isset($info['extension']) && in_array(strtolower($info['extension']), $validExtensions)) {
                if (in_array(strtolower($info['filename']), $possibleNames)) {
                    $iconPath = $realRoot . "/" . $file;
                    break;
                }
            }
        }

        // Fallback Icon
        if ($iconPath == "") {
            foreach ($dirFiles as $file) {
                $info = pathinfo($file);
                if (isset($info['extension']) && in_array(strtolower($info['extension']), $validExtensions)) {
                    $iconPath = $realRoot . "/" . $file;
                    break;
                }
            }
        }

        if ($iconPath == "" || !file_exists($iconPath)) {
            throw new Exception("<b>No Image Found!</b><br>Please include an icon (png/jpg) in your ZIP.");
        }

        // 6. Generate Icons & Delete Original
        $imgData = @file_get_contents($iconPath);
        if(!$imgData) throw new Exception("Could not read image file.");

        $srcImg = @imagecreatefromstring($imgData);
        if(!$srcImg) throw new Exception("Image corrupted or GD Library missing.");
        
        makeIcon($srcImg, 192, $realRoot . "/icon-192.png");
        makeIcon($srcImg, 512, $realRoot . "/icon-512.png");
        imagedestroy($srcImg);
        @unlink($iconPath); 

        // 7. Create Manifest
        $manifest = [
            "name" => $appName,
            "short_name" => $appName,
            "start_url" => "./" . $entryFile,
            "scope" => "./",
            "display" => "standalone",
            "orientation" => "portrait",
            "theme_color" => $color,
            "background_color" => $color,
            "icons" => [
                ["src" => "icon-192.png", "sizes" => "192x192", "type" => "image/png"],
                ["src" => "icon-512.png", "sizes" => "512x512", "type" => "image/png"]
            ]
        ];
        file_put_contents($realRoot . "/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 8. Service Worker
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isDir()) {
                $path = str_replace($realRoot . "/", "", $file->getPathname());
                $path = str_replace("\\", "/", $path);
                $files[] = "./" . $path;
            }
        }
        $jsonFiles = json_encode($files, JSON_UNESCAPED_SLASHES); 
        
        $swContent = <<<JS
const CACHE_NAME = "pwa-cache-v1";
const ASSETS = $jsonFiles;

self.addEventListener("install", e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(c => c.addAll(ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener("fetch", e => {
  e.respondWith(
    caches.match(e.request).then(r => r || fetch(e.request))
  );
});
JS;
        file_put_contents($realRoot . "/service-worker.js", $swContent);

        // 9. Inject HTML/PHP
        $targetFile = $realRoot . "/" . $entryFile;
        $fileContent = file_get_contents($targetFile);
        
        if (strpos($fileContent, 'manifest.json') === false) {
            $injection = '
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="'.$color.'">
<link rel="apple-touch-icon" href="icon-192.png">
<script>
if("serviceWorker" in navigator){
 navigator.serviceWorker.register("service-worker.js");
}
</script>
</head>';
            if(strpos($fileContent, "</head>") !== false){
                $fileContent = str_replace("</head>", $injection, $fileContent);
            } else {
                $fileContent = $injection . $fileContent;
            }
            file_put_contents($targetFile, $fileContent);
        }

        // 10. Zip & Download
        $downloadFile = $safeName . ".zip";
        $finalZipPath = $tempDir . "/" . $downloadFile;
        
        $z = new ZipArchive;
        if ($z->open($finalZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
             throw new Exception("Cannot create final ZIP.");
        }
        
        $filesIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($filesIterator as $file) {
            if (!$file->isDir()) {
                $relativePath = str_replace($realRoot . "/", "", $file->getPathname());
                $z->addFile($file->getPathname(), $relativePath);
            }
        }
        $z->close();

        if (file_exists($finalZipPath)) {
            if (ob_get_length()) ob_clean();
            
            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $downloadFile . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($finalZipPath));
            readfile($finalZipPath);
            
            cleanup($tempDir);
            exit;
        } else {
            throw new Exception("Final ZIP generation failed.");
        }

    } catch (Exception $e) {
        error_log($e->getMessage());
        showUI("Error", $e->getMessage(), true);
        if(isset($tempDir)) cleanup($tempDir);
        exit;
    }
}

// --- HELPER FUNCTIONS ---

function makeIcon($src, $size, $path) {
    $icon = imagecreatetruecolor($size, $size);
    imagealphablending($icon, false);
    imagesavealpha($icon, true);
    imagecopyresampled($icon, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    imagepng($icon, $path);
    imagedestroy($icon);
}

function cleanup($dir) {
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    @rmdir($dir);
}

function showUI($title, $message, $isError = false) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PWA Builder</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin: 0; font-family: system-ui, Arial, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.card { background: #fff; width: 100%; max-width: 420px; padding: 30px; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); text-align: center; animation: fadeIn 0.5s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
h2 { margin: 0 0 10px; color: #333; }
p { font-size: 14px; color: #666; margin-bottom: 20px; line-height: 1.5; }
.input-group { text-align: left; margin-bottom: 15px; }
label { display: block; font-size: 12px; font-weight: bold; color: #555; margin-bottom: 5px; text-transform: uppercase; }
input { width: 100%; padding: 12px; margin-top: 5px; border-radius: 8px; border: 1px solid #ddd; font-size: 14px; box-sizing: border-box; }
input:focus { border-color: #764ba2; outline: none; }
input[type="color"] { height: 45px; padding: 2px; cursor: pointer; }
button { width: 100%; background: #4f46e5; color: #fff; border: none; padding: 14px; border-radius: 8px; font-weight: 600; font-size: 16px; margin-top: 15px; cursor: pointer; transition: 0.2s; }
button:hover { background: #4338ca; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(79, 70, 229, 0.4); }
.error-box { background: #fee2e2; color: #b91c1c; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fca5a5; font-size: 14px; }
.file-hint { font-size: 11px; color: #888; margin-top: 4px; display: block; }
</style>

<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#4f46e5">
<link rel="apple-touch-icon" href="icon-192.png">
<script>
if("serviceWorker" in navigator){
 navigator.serviceWorker.register("service-worker.js");
}
</script>

</head>
<body>
<div class="card">
    <?php if ($isError): ?>
        <div style="font-size: 40px; margin-bottom: 10px;">⚠️</div>
        <h2>Oops! Failed</h2>
        <div class="error-box"><?php echo $message; ?></div>
        <button onclick="history.back()">Try Again</button>
    <?php else: ?>
        <div style="font-size: 40px; margin-bottom: 10px;">🚀</div>
        <h2>PWA Builder</h2>
        <p>Upload website ZIP → Get Ready PWA</p>
        
        <form action="" method="post" enctype="multipart/form-data">
            <div class="input-group">
                <label>App Name</label>
                <input type="text" name="app_name" placeholder="Ex: My App" required>
            </div>
            
            <div class="input-group">
                <label>Theme Color</label>
                <input type="color" name="theme_color" value="#4f46e5">
            </div>
            
            <div class="input-group">
                <label>Website ZIP File</label>
                <input type="file" name="zip" accept=".zip" required>
                <span class="file-hint">Must contain index.html OR index.php and icon img.</span>
            </div>
            
            <button type="submit">Build PWA ZIP</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
<?php
}
showUI("", "");
?>
