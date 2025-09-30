<?php
// Simplified PHP Web Proxy

// --- CONFIGURATION ---
// Report all errors except notices. This is good for development.
// For production, you might want to log errors to a file instead.
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1); // Show errors for easier debugging

// Set a reasonable time limit for the script to prevent timeouts on slow sites.
set_time_limit(120);

// --- URL HELPERS ---

/**
 * Resolves a relative URL to an absolute URL.
 *
 * @param string $relative The relative URL to resolve.
 * @param string $base The base URL to resolve against.
 * @return string The resolved, absolute URL.
 */
function resolve_url(string $relative, string $base): string {
    $relative = trim($relative);
    // If it's already a full URL, a data URI, or a mailto link, return it as is.
    if (preg_match('~^(https?://|data:|mailto:|#)~i', $relative)) {
        return $relative;
    }

    $base_parts = parse_url($base);
    if (empty($base_parts['scheme']) || empty($base_parts['host'])) {
        return $relative; // Cannot resolve without a valid base.
    }

    // Handle protocol-relative URLs like //example.com/path
    if (strpos($relative, '//') === 0) {
        return $base_parts['scheme'] . ':' . $relative;
    }

    // Handle root-relative paths like /some/page.html
    if ($relative[0] === '/') {
        $path = $relative;
    } else {
        // Handle relative paths like 'page.html' or '../style.css'
        $path = dirname($base_parts['path'] ?? '/') . '/' . $relative;
    }

    // Resolve '..' and '.' segments.
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($parts);
        } else {
            $parts[] = $part;
        }
    }
    $abs_path = '/' . implode('/', $parts);

    $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';
    return $base_parts['scheme'] . '://' . $base_parts['host'] . $port . $abs_path;
}

/**
 * Creates a proxy URL for a given target URL.
 *
 * @param string $url The target URL.
 * @return string The URL that will proxy the target URL.
 */
function proxy_for(string $url): string {
    // Get the path of the current script.
    $script_path = strtok($_SERVER['REQUEST_URI'], '?');
    return $script_path . '?url=' . rawurlencode($url);
}

// --- MAIN PROXY LOGIC ---

// 1. Get the target URL from the query string.
$target_url_raw = $_GET['url'] ?? null;

if (!$target_url_raw) {
    // If no URL is provided, redirect to the main page (index.html).
    header('Location: index.html');
    exit;
}

// 2. Validate and prepare the URL.
$target_url = $target_url_raw;
if (!preg_match('~^https?://~i', $target_url)) {
    $target_url = 'http://' . $target_url;
}
$target_parts = parse_url($target_url);

if (empty($target_parts['host'])) {
    http_response_code(400);
    die("Invalid URL provided.");
}

// 3. Initialize cURL to fetch the content.
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string.
curl_setopt($ch, CURLOPT_HEADER, true);         // Include the headers in the output.
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects.
curl_setopt($ch, CURLOPT_AUTOREFERER, true);    // Automatically set Referer header.
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);   // Connection timeout.
curl_setopt($ch, CURLOPT_TIMEOUT, 30);          // Total request timeout.
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);// Don't verify SSL cert (for self-signed certs).
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);// Don't verify SSL host.
curl_setopt($ch, CURLOPT_ENCODING, '');         // Handle gzip, deflate, etc. automatically.

// Forward essential request headers from the client.
$headers_to_forward = [];
$forwardable_headers = ['Accept', 'Accept-Language', 'User-Agent', 'DNT'];
foreach (getallheaders() as $key => $value) {
    if (in_array($key, $forwardable_headers)) {
        $headers_to_forward[] = "$key: $value";
    }
}
// Set the Host header to the target's host. This is crucial for virtual hosting.
$headers_to_forward[] = 'Host: ' . $target_parts['host'];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_to_forward);

// 4. Execute the cURL request.
$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502); // Bad Gateway
    die("Failed to fetch the upstream URL: " . curl_error($ch));
}

// 5. Get information about the final request.
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); // The URL after all redirects.
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 6. Separate headers and body from the cURL response.
$headers_raw = substr($response, 0, $header_size);
$body = substr($response, $header_size);

// 7. Process and forward response headers to the client.
http_response_code($status_code);
$header_lines = preg_split('/\\r\\n|\\n|\\r/', trim($headers_raw));

// Headers that can interfere with the proxy's operation.
$headers_to_block = [
    'content-security-policy',
    'x-frame-options',
    'strict-transport-security',
    'content-length',
    'transfer-encoding',
];

foreach ($header_lines as $line) {
    // Skip the HTTP status line (e.g., "HTTP/1.1 200 OK").
    if (preg_match('/^HTTP\//i', $line)) {
        continue;
    }

    list($key, $value) = array_pad(explode(':', $line, 2), 2, '');
    $key_lower = strtolower(trim($key));
    $value = trim($value);

    if (in_array($key_lower, $headers_to_block)) {
        continue;
    }

    // Rewrite the Location header for redirects.
    if ($key_lower === 'location') {
        $new_location = resolve_url($value, $final_url);
        header("Location: " . proxy_for($new_location), true);
        continue;
    }

    // Forward the header, but don't override existing ones (like Location).
    header("$key: $value", false);
}

// 8. Rewrite the content body based on its type.
$content_type_main = trim(explode(';', $content_type)[0]);

if ($content_type_main === 'text/html') {
    // Use DOMDocument to safely parse and rewrite HTML.
    // This is more reliable than using regular expressions on HTML.
    libxml_use_internal_errors(true); // Suppress warnings from malformed HTML.
    $doc = new DOMDocument();
    if ($body) {
        // LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD prevents DOMDocument
        // from adding extra <html> and <body> tags.
        $doc->loadHTML($body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    }

    // Determine the base URL for resolving relative paths.
    // Check for a <base> tag first.
    $base_href = $final_url;
    $base_tags = $doc->getElementsByTagName('base');
    if ($base_tags->length > 0 && $base_tags[0]->hasAttribute('href')) {
        $base_href = resolve_url($base_tags[0]->getAttribute('href'), $final_url);
    }

    // A map of tags and attributes that may contain URLs to rewrite.
    $rewrite_map = [
        'a'      => ['href'],
        'img'    => ['src', 'longdesc'],
        'script' => ['src'],
        'link'   => ['href'],
        'form'   => ['action'],
        'iframe' => ['src'],
        'video'  => ['poster'],
        'audio'  => ['src'],
        'source' => ['src'],
    ];

    foreach ($rewrite_map as $tag => $attrs) {
        foreach ($doc->getElementsByTagName($tag) as $element) {
            foreach ($attrs as $attr) {
                if ($element->hasAttribute($attr)) {
                    $original_url = $element->getAttribute($attr);
                    $proxied_url = proxy_for(resolve_url($original_url, $base_href));
                    $element->setAttribute($attr, $proxied_url);
                }
            }
            // Special handling for srcset attribute (responsive images).
            if ($element->hasAttribute('srcset')) {
                $srcset = $element->getAttribute('srcset');
                $new_srcset = implode(', ', array_map(function($part) use ($base_href) {
                    $parts = preg_split('/\s+/', trim($part), 2);
                    $url = $parts[0];
                    $descriptor = $parts[1] ?? '';
                    return proxy_for(resolve_url($url, $base_href)) . ' ' . $descriptor;
                }, explode(',', $srcset)));
                $element->setAttribute('srcset', $new_srcset);
            }
        }
    }
    
    // Function to rewrite URLs inside CSS content (e.g., style attributes/tags).
    $css_rewrite_callback = function($matches) use ($base_href) {
        $url = trim($matches[1], " \t\n'\"");
        return 'url("' . proxy_for(resolve_url($url, $base_href)) . '")';
    };

    // Rewrite URLs in inline <style> blocks.
    foreach ($doc->getElementsByTagName('style') as $element) {
        $element->nodeValue = preg_replace_callback('/url\(([^)]+)\)/i', $css_rewrite_callback, $element->nodeValue);
    }
    
    // Rewrite URLs in inline `style` attributes.
    foreach ($doc->getElementsByTagName('*') as $element) {
        if ($element->hasAttribute('style')) {
            $new_style = preg_replace_callback('/url\(([^)]+)\)/i', $css_rewrite_callback, $element->getAttribute('style'));
            $element->setAttribute('style', $new_style);
        }
    }

    echo $doc->saveHTML();

} elseif ($content_type_main === 'text/css') {
    // For external CSS files, rewrite url() values.
    $body = preg_replace_callback('/url\(([^)]+)\)/i', function($matches) use ($final_url) {
        $url = trim($matches[1], " \t\n'\"");
        return 'url("' . proxy_for(resolve_url($url, $final_url)) . '")';
    }, $body);
    echo $body;

} else {
    // For all other content types (images, fonts, etc.), pass them through directly.
    echo $body;
}

exit;