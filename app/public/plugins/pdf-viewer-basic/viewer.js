(function() {
    'use strict';

    function pdf_viewer_basic_init(options) {
        var container = options.container;
        var file = options.file;

        container.innerHTML = `
            <div class="pdf-viewer-basic">
                <div class="pdf-viewer-basic-toolbar">
                    <span class="pdf-viewer-basic-filename">${escapeHtml(file.name)}</span>
                    <a class="pdf-viewer-basic-download" href="/files/${file.id}/download">&#x2193; Descargar</a>
                </div>
                <div class="pdf-viewer-basic-frame-wrapper">
                    <iframe
                        class="pdf-viewer-basic-iframe"
                        src="/media/${file.id}/preview"
                        title="${escapeHtml(file.name)}"
                        frameborder="0">
                    </iframe>
                </div>
            </div>
        `;

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }
    }

    if (typeof window !== 'undefined') {
        window.pdf_viewer_basic_init = pdf_viewer_basic_init;
    }
})();
