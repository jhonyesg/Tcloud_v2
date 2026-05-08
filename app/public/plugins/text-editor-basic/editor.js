(function() {
    'use strict';

    function text_editor_basic_init(options) {
        var container = options.container;
        var file = options.file;
        var config = options.config || {};
        var showLineNumbers = config.lineNumbers !== false;

        container.innerHTML = `
            <div class="text-editor-basic">
                <div class="text-editor-basic-toolbar">
                    <span class="text-editor-basic-filename">${escapeHtml(file.name)}</span>
                    <span class="text-editor-basic-meta">${file.mime_type || 'text/plain'} &middot; ${formatSize(file.size)}</span>
                    <a class="text-editor-basic-download" href="/files/${file.id}/download">&#x2193; Descargar</a>
                </div>
                <div class="text-editor-basic-content" id="teb-content">
                    <div class="text-editor-basic-loading">Cargando contenido...</div>
                </div>
            </div>
        `;

        var contentArea = container.querySelector('#teb-content');
        var mime = file.mime_type || 'text/plain';
        var url = '/media/' + file.id + '/preview';

        fetch(url)
            .then(function(res) {
                if (!res.ok) throw new Error('Error al cargar el archivo');
                return res.text();
            })
            .then(function(text) {
                var highlighted = highlightSyntax(text, mime);
                var lines = highlighted.split('\n');
                var html = '';

                if (showLineNumbers) {
                    html += '<div class="text-editor-basic-scroll">';
                    html += '<table class="text-editor-basic-table"><tbody>';
                    for (var i = 0; i < lines.length; i++) {
                        html += '<tr>';
                        html += '<td class="text-editor-basic-line-num">' + (i + 1) + '</td>';
                        html += '<td class="text-editor-basic-line-code">' + lines[i] + '</td>';
                        html += '</tr>';
                    }
                    html += '</tbody></table>';
                    html += '</div>';
                } else {
                    html += '<div class="text-editor-basic-scroll">';
                    html += '<pre class="text-editor-basic-pre"><code>' + highlighted + '</code></pre>';
                    html += '</div>';
                }

                contentArea.innerHTML = html;
            })
            .catch(function(err) {
                contentArea.innerHTML = '<div class="text-editor-basic-error">Error: ' + escapeHtml(err.message) + '</div>';
            });

        function highlightSyntax(text, mime) {
            var escaped = escapeHtml(text);

            if (mime === 'application/json') {
                escaped = escaped.replace(/("(?:[^"\\]|\\.)*")\s*:/g, '<span class="teb-key">$1</span>:');
                escaped = escaped.replace(/:\s*("(?:[^"\\]|\\.)*")/g, ': <span class="teb-string">$1</span>');
                escaped = escaped.replace(/:\s*(\d+\.?\d*)/g, ': <span class="teb-number">$1</span>');
                escaped = escaped.replace(/:\s*(true|false|null)/g, ': <span class="teb-keyword">$1</span>');
            } else if (mime === 'text/html') {
                escaped = escaped.replace(/(&lt;\/?)([\w-]+)/g, '$1<span class="teb-tag">$2</span>');
                escaped = escaped.replace(/([\w-]+)(=)/g, '<span class="teb-attr">$1</span>$2');
                escaped = escaped.replace(/(=)(&quot;[^&]*&quot;)/g, '$1<span class="teb-string">$2</span>');
            } else if (mime === 'text/css') {
                escaped = escaped.replace(/([\w-]+)\s*\{/g, '<span class="teb-selector">$1</span>{');
                escaped = escaped.replace(/([\w-]+)\s*:/g, '<span class="teb-attr">$1</span>:');
                escaped = escaped.replace(/:\s*([^;{}]+)(;)/g, ': <span class="teb-value">$1</span>$2');
            } else if (mime === 'text/javascript') {
                keywords.forEach(function(kw) {
                    var regex = new RegExp('\\b(' + kw + ')\\b', 'g');
                    escaped = escaped.replace(regex, '<span class="teb-keyword">$1</span>');
                });
                escaped = escaped.replace(/(\/\/.*?)(\n|$)/g, '<span class="teb-comment">$1</span>$2');
                escaped = escaped.replace(/(\/\*[\s\S]*?\*\/)/g, '<span class="teb-comment">$1</span>');
                escaped = escaped.replace(/(&apos;[^&apos;]*&apos;|&quot;[^&quot;]*&quot;|&#039;[^&#039;]*&#039;)/g, '<span class="teb-string">$1</span>');
            }

            return escaped;
        }

        var keywords = ['var', 'let', 'const', 'function', 'return', 'if', 'else', 'for', 'while', 'class',
            'new', 'this', 'import', 'export', 'default', 'from', 'try', 'catch', 'throw', 'switch',
            'case', 'break', 'continue', 'typeof', 'instanceof', 'null', 'undefined', 'true', 'false'];

        function formatSize(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            var k = 1024;
            var sizes = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }
    }

    if (typeof window !== 'undefined') {
        window.text_editor_basic_init = text_editor_basic_init;
    }
})();
