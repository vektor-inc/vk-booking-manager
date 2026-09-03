import { phpVars as a } from "@php-wasm/util";
function l(i = fetch) {
  const e = {};
  return async function(t, r) {
    if (!e[t]) {
      e[t] = {
        responsePromise: i(t, r),
        async nextResponse() {
          const n = await e[t].responsePromise, [c, $] = e[t].unlockedBodyStream.tee();
          return e[t].unlockedBodyStream = c, new Response($, {
            status: n.status,
            statusText: n.statusText,
            headers: n.headers
          });
        }
      };
      const s = await e[t].responsePromise;
      e[t].unlockedBodyStream = s.body;
    }
    return e[t].nextResponse();
  };
}
const p = "8.3", u = async (i, e, o, t = !0) => {
  const r = `/tmp/file-${Math.random()}.zip`;
  if (e instanceof File) {
    const n = e;
    e = r, await i.writeFile(
      e,
      new Uint8Array(await n.arrayBuffer())
    );
  }
  const s = a({
    zipPath: e,
    extractToPath: o,
    overwriteFiles: t
  });
  await i.run({
    code: `<?php
        function unzip($zipPath, $extractTo, $overwriteFiles = true)
        {
            if (!is_dir($extractTo)) {
                mkdir($extractTo, 0777, true);
            }
            $zip = new ZipArchive;
            $res = $zip->open($zipPath);
            if ($res === TRUE) {
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$filename = $zip->getNameIndex($i);
					$fileinfo = pathinfo($filename);
					$extractFilePath = rtrim($extractTo, '/') . '/' . $filename;
					// Check if file exists and $overwriteFiles is false
					if (!file_exists($extractFilePath) || $overwriteFiles) {
						// Extract file
						$zip->extractTo($extractTo, $filename);
					}
				}
				$zip->close();
				chmod($extractTo, 0777);
            } else {
                $fileSize = file_exists($zipPath) ? filesize($zipPath) : 'unknown';
                throw new Exception("Could not unzip file. Error code: " . $res . ". File size: " . $fileSize . " bytes.");
            }
        }
        unzip(${s.zipPath}, ${s.extractToPath}, ${s.overwriteFiles});
        `
  }), await i.fileExists(r) && await i.unlink(r);
}, d = async (i, e) => {
  const o = `/tmp/file${Math.random()}.zip`, t = a({
    directoryPath: e,
    outputPath: o
  });
  await i.run({
    code: `<?php
		function zipDirectory($directoryPath, $outputPath) {
			$zip = new ZipArchive;
			$res = $zip->open($outputPath, ZipArchive::CREATE);
			if ($res !== TRUE) {
				throw new Exception('Failed to create ZIP');
			}
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($directoryPath)
			);
			foreach ($files as $file) {
				$file = strval($file);
				if (is_dir($file)) {
					continue;
				}
				$zip->addFile($file, substr($file, strlen($directoryPath)));
			}
			$zip->close();
			chmod($outputPath, 0777);
		}
		zipDirectory(${t.directoryPath}, ${t.outputPath});
		`
  });
  const r = await i.readFileAsBuffer(o);
  return i.unlink(o), r;
};
export {
  p as RecommendedPHPVersion,
  l as createMemoizedFetch,
  u as unzipFile,
  d as zipDirectory
};
//# sourceMappingURL=index.js.map
