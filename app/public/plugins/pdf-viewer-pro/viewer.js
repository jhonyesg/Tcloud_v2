(function() {
    'use strict';

    function pdf_viewer_pro_init(options) {
        const container = options.container;
        const file = options.file;
        const config = options.config || {};

        container.innerHTML = `
            <div class="pdf-viewer-pro" style="height: ${config.height || '600px'}; display: flex; flex-direction: column;">
                <div class="pdf-viewer-toolbar" style="padding: 10px; background: #f5f5f5; border-bottom: 1px solid #ddd; display: flex; gap: 10px; align-items: center;">
                    <button id="pdf-zoom-out" style="padding: 5px 10px; cursor: pointer;">-</button>
                    <span id="pdf-zoom-level">100%</span>
                    <button id="pdf-zoom-in" style="padding: 5px 10px; cursor: pointer;">+</button>
                    <span style="margin-left: auto;">${file.name}</span>
                </div>
                <div class="pdf-viewer-content" style="flex: 1; overflow: auto; padding: 20px; background: #666;">
                    <div style="text-align: center; color: #ccc; padding: 50px;">
                        <p>Visor PDF - Archivo: ${file.name}</p>
                        <p style="font-size: 12px; margin-top: 10px;">Tamaño: ${formatSize(file.size)}</p>
                        <p style="font-size: 12px; margin-top: 5px;">Tipo MIME: ${file.mime_type || 'application/pdf'}</p>
                        <p style="margin-top: 20px; font-style: italic;">Para ver el PDF real, implemente la carga de PDF.js o una librería similar.</p>
                        <p style="font-size: 11px; margin-top: 30px; color: #999;">URL del archivo: /files/${file.id}/preview</p>
                    </div>
                </div>
            </div>
        `;

        let zoomLevel = 100;
        const zoomInBtn = container.querySelector('#pdf-zoom-in');
        const zoomOutBtn = container.querySelector('#pdf-zoom-out');
        const zoomLevelSpan = container.querySelector('#pdf-zoom-level');

        if (zoomInBtn && zoomOutBtn) {
            zoomInBtn.addEventListener('click', function() {
                if (zoomLevel < 200) {
                    zoomLevel += 25;
                    updateZoom();
                }
            });
            zoomOutBtn.addEventListener('click', function() {
                if (zoomLevel > 50) {
                    zoomLevel -= 25;
                    updateZoom();
                }
            });
        }

        function updateZoom() {
            if (zoomLevelSpan) {
                zoomLevelSpan.textContent = zoomLevel + '%';
            }
            const content = container.querySelector('.pdf-viewer-content');
            if (content) {
                content.style.transform = 'scale(' + (zoomLevel / 100) + ')';
                content.style.transformOrigin = 'top center';
            }
        }

        function formatSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    }

    if (typeof window !== 'undefined') {
        window.pdf_viewer_pro_init = pdf_viewer_pro_init;
    }
})();