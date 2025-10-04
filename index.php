<?php

// ===================================================================================
//
//  A Self-Contained PHP Web Proxy
//
//  This script is designed to be a robust, single-file solution that avoids
//  common server configuration issues. It combines the user interface (input form)
//  and the proxy engine into one file, `index.php`, which is the most likely
//  file to be executed by a web server.
//
//  How it works:
//  1. If no `?url=` parameter is provided, it displays a user-friendly HTML form.
//  2. If a `?url=` parameter is present, it switches to proxy mode and fetches
//     the requested content.
//
// ===================================================================================

// --- SCRIPT CONFIGURATION ---

// Set a reasonable time limit to prevent timeouts on slow remote servers.
set_time_limit(120);
// Suppress errors from being displayed to the end-user for security and cleanliness.
// Errors will still be logged to the server's error log, which is the proper way.
ini_set('display_errors', 0);
error_reporting(0);
// Suppress DOMDocument warnings for malformed HTML, as we can't control remote site quality.
libxml_use_internal_errors(true);


// --- URL & PROXY HELPER FUNCTIONS ---

/**
 * Resolves a relative URL to an absolute URL based on a base URL.
 * Handles protocol-relative, root-relative, and directory-relative paths.
 *
 * @param string $relative The relative URL.
 * @param string $base     The base URL to resolve against.
 * @return string The fully resolved, absolute URL.
 */
function resolve_url(string $relative, string $base): string {
    $relative = trim($relative);
    if (preg_match('~^(https?://|data:|blob:|mailto:|javascript:|#)~i', $relative)) {
        return $relative; // It's already absolute or a special URI scheme.
    }

    $base_parts = parse_url($base);
    if (empty($base_parts['scheme']) || empty($base_parts['host'])) {
        return $relative; // Can't resolve if the base URL is invalid.
    }

    if (strpos($relative, '//') === 0) {
        return $base_parts['scheme'] . ':' . $relative; // Protocol-relative URL.
    }

    $path = $base_parts['path'] ?? '/';
    if ($relative[0] === '/') {
        $path = $relative; // Root-relative URL.
    } else {
        $path = dirname($path) . '/' . $relative; // Directory-relative URL.
    }

    // Resolve navigation segments like /./ and /../
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') array_pop($parts);
        else $parts[] = $part;
    }
    $abs_path = '/' . implode('/', $parts);

    $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';
    return $base_parts['scheme'] . '://' . $base_parts['host'] . $port . $abs_path;
}

/**
 * Creates a proxied URL that points back to this script.
 *
 * @param string $url The target URL to proxy.
 * @return string The new URL that will route through this script.
 */
function proxy_for(string $url): string {
    // `$_SERVER['PHP_SELF']` is used to ensure the link always points to this script.
    return $_SERVER['PHP_SELF'] . '?url=' . rawurlencode($url);
}


// --- DISPLAY FUNCTIONS ---

/**
 * Displays the main user interface with the URL input form.
 * This is shown when the script is accessed without a `url` parameter.
 */
function display_form(): void {
    $script_name = htmlspecialchars($_SERVER['PHP_SELF']);
    // Using a HEREDOC for clean, readable HTML.
    echo <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Web Proxy</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: #f0f2f5;
        }
        .container {
            text-align: center;
            background: #fff;
            padding: 2.5rem;
            border-radius: 1rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            width: 90%;
            max-width: 600px;
        }
        h1 {
            font-size: 2.2rem;
            color: #333;
            margin-bottom: 1rem;
        }
        .proxy-form {
            display: flex;
            border: 1px solid #ddd;
            border-radius: 0.5rem;
            overflow: hidden;
            margin-top: 1.5rem;
        }
        .proxy-input {
            flex-grow: 1;
            border: none;
            padding: 0.9rem 1rem;
            font-size: 1rem;
            outline: none;
        }
        .proxy-button {
            border: none;
            background: #007bff;
            color: white;
            padding: 0 1.8rem;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .proxy-button:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Web Proxy</h1>
        <form action="{$script_name}" method="GET" class="proxy-form">
            <input type="text" name="url" class="proxy-input" placeholder="https://example.com" required autofocus>
            <button type="submit" class="proxy-button">Go</button>
        </form>
    </div>
</body>
</html>
HTML;
}


/**
 * The main engine that fetches, processes, and serves the proxied content.
 *
 * @param string $target_url The URL of the page to proxy.
 */
function run_proxy(string $target_url): void {
    // --- 1. INITIALIZE cURL ---
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $target_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return content as a string.
    curl_setopt($ch, CURLOPT_HEADER, true);         // Include headers in the response.
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects automatically.
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);    // Set Referer on redirects.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);   // Connection timeout.
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);          // Total request timeout.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);// Lax SSL verification.
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');         // Handle compressed content (gzip, etc).
    // Set a generic, modern User-Agent.
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    // Set the Host header to the target's host. This is crucial for virtual hosting.
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . parse_url($target_url, PHP_URL_HOST)]);


    // --- 2. EXECUTE REQUEST & GET INFO ---
    $response = curl_exec($ch);
    if ($response === false) {
        http_response_code(502); // Bad Gateway
        die("<h3>Proxy Error</h3><p>Could not retrieve the requested page.</p><p>cURL Error: " . htmlspecialchars(curl_error($ch)) . "</p>");
    }

    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $final_url   = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);


    // --- 3. PROCESS & FORWARD HEADERS ---
    $headers_raw = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    // Set the HTTP status code from the remote server.
    http_response_code($status_code);

    $headers_to_block = [
        'content-security-policy', 'x-frame-options', 'strict-transport-security',
        'content-length', 'transfer-encoding', 'content-encoding'
    ];

    $header_lines = preg_split('/\\r\\n|\\n|\\r/', trim($headers_raw));
    foreach ($header_lines as $line) {
        if (preg_match('/^HTTP\//i', $line)) continue; // Skip HTTP status lines.

        list($key, $value) = array_pad(explode(':', $line, 2), 2, '');
        $key_lower = strtolower(trim($key));

        if ($key_lower && !in_array($key_lower, $headers_to_block)) {
            if ($key_lower === 'location') {
                // Rewrite redirect headers to point back to the proxy.
                header("Location: " . proxy_for(resolve_url(trim($value), $final_url)), true);
            } else {
                // Forward other safe headers.
                header(trim($line), false);
            }
        }
    }
    // Set the Content-Type header last, using the most reliable value.
    // This is the most critical fix for preventing code-display issues.
    if ($content_type) {
        header('Content-Type: ' . $content_type);
    }


    // --- 4. REWRITE & OUTPUT BODY ---
    $content_type_main = trim(explode(';', $content_type)[0]);
    $base_href = $final_url;

    $css_rewrite_callback = function($matches) use ($base_href) {
        $url = trim($matches[1], " \t\n'\"");
        return 'url("' . proxy_for(resolve_url($url, $base_href)) . '")';
    };

    if ($content_type_main === 'text/html') {
        $doc = new DOMDocument();
        if ($body) $doc->loadHTML($body);

        // Check for a <base> tag which would change URL resolution logic.
        $base_tags = $doc->getElementsByTagName('base');
        if ($base_tags->length > 0 && $base_tags[0]->hasAttribute('href')) {
            $base_href = resolve_url($base_tags[0]->getAttribute('href'), $final_url);
        }

        $rewrite_map = [
            'a' => 'href', 'area' => 'href', 'link' => 'href', 'img' => 'src',
            'script' => 'src', 'iframe' => 'src', 'form' => 'action',
            'video' => 'poster', 'audio' => 'src', 'source' => 'src',
        ];
        foreach ($rewrite_map as $tag => $attr) {
            foreach ($doc->getElementsByTagName($tag) as $el) {
                if ($el->hasAttribute($attr)) {
                    $el->setAttribute($attr, proxy_for(resolve_url($el->getAttribute($attr), $base_href)));
                }
            }
        }

        // Rewrite srcset for responsive images
        foreach ($doc->getElementsByTagName('img') as $el) {
            if ($el->hasAttribute('srcset')) {
                $new_srcset = implode(', ', array_map(function($part) use ($base_href) {
                    $parts = preg_split('/\s+/', trim($part), 2);
                    return proxy_for(resolve_url($parts[0], $base_href)) . ' ' . ($parts[1] ?? '');
                }, explode(',', $el->getAttribute('srcset'))));
                $el->setAttribute('srcset', $new_srcset);
            }
        }

        // Rewrite URLs in inline styles
        foreach ($doc->getElementsByTagName('style') as $el) {
            $el->nodeValue = preg_replace_callback('/url\(([^)]+)\)/i', $css_rewrite_callback, $el->nodeValue);
        }
        foreach ($doc->getElementsByTagName('*') as $el) {
            if ($el->hasAttribute('style')) {
                $el->setAttribute('style', preg_replace_callback('/url\(([^)]+)\)/i', $css_rewrite_callback, $el->getAttribute('style')));
            }
        }

        echo $doc->saveHTML();

    } elseif ($content_type_main === 'text/css') {
        echo preg_replace_callback('/url\(([^)]+)\)/i', $css_rewrite_callback, $body);
    } else {
        // For all other content types (images, fonts, etc.), pass them through directly.
        echo $body;
    }
}


// --- MAIN ROUTER ---

// This is the entry point of the script.
// It decides whether to show the form or run the proxy.
$target_url_raw = $_GET['url'] ?? null;

if ($target_url_raw) {
    // A URL was provided, so run the proxy.
    $target_url = $target_url_raw;
    if (!preg_match('~^https?://~i', $target_url)) {
        $target_url = 'http://' . $target_url;
    }
    run_proxy($target_url);
} else {
    // No URL was provided, so display the user-friendly form.
    display_form();
}

exit;