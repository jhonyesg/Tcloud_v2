(function() {
    'use strict';

    function image_viewer_basic_init(options) {
        var container = options.container;
        var file = options.file;
        var config = options.config || {};

        var zoomLevel = 100;
        var rotation = 0;

        container.innerHTML = `
            <div class="image-viewer-basic">
                <div class="image-viewer-basic-toolbar">
                    <div class="image-viewer-basic-controls">
                        <button class="image-viewer-basic-btn" id="ivb-zoom-out" title="Zoom out">&#x2212;</button>
                        <span class="image-viewer-basic-zoom-level" id="ivb-zoom-level">100%</span>
                        <button class="image-viewer-basic-btn" id="ivb-zoom-in" title="Zoom in">+</button>
                        <button class="image-viewer-basic-btn" id="ivb-rotate" title="Rotar 90&#176;">&#x21bb;</button>
                        <button class="image-viewer-basic-btn" id="ivb-reset" title="Restablecer">&#x2302;</button>
                    </div>
                    <div class="image-viewer-basic-file-info">
                        <span class="image-viewer-basic-filename">${escapeHtml(file.name)}</span>
                        <span class="image-viewer-basic-filesize">${formatSize(file.size)}</span>
                        <a class="image-viewer-basic-btn image-viewer-basic-download" href="/files/${file.id}/download" title="Descargar">&#x2193; Descargar</a>
                    </div>
                </div>
                <div class="image-viewer-basic-viewport">
                    <img id="ivb-image"
                         class="image-viewer-basic-img"
                         src="/media/${file.id}/preview"
                         alt="${escapeHtml(file.name)}"
                         draggable="false">
                </div>
            </div>
        `;

        var img = container.querySelector('#ivb-image');
        var zoomInBtn = container.querySelector('#ivb-zoom-in');
        var zoomOutBtn = container.querySelector('#ivb-zoom-out');
        var rotateBtn = container.querySelector('#ivb-rotate');
        var resetBtn = container.querySelector('#ivb-reset');
        var zoomLevelSpan = container.querySelector('#ivb-zoom-level');
        var viewport = container.querySelector('.image-viewer-basic-viewport');

        zoomInBtn.addEventListener('click', function() {
            if (zoomLevel < 400) { zoomLevel += 25; updateTransform(); }
        });

        zoomOutBtn.addEventListener('click', function() {
            if (zoomLevel > 25) { zoomLevel -= 25; updateTransform(); }
        });

        rotateBtn.addEventListener('click', function() {
            rotation = (rotation + 90) % 360;
            updateTransform();
        });

        resetBtn.addEventListener('click', function() {
            zoomLevel = 100;
            rotation = 0;
            updateTransform();
        });

        viewport.addEventListener('wheel', function(e) {
            e.preventDefault();
            if (e.deltaY < 0 && zoomLevel < 400) { zoomLevel += 10; }
            else if (e.deltaY > 0 && zoomLevel > 25) { zoomLevel -= 10; }
            updateTransform();
        }, { passive: false });

        function updateTransform() {
            img.style.transform = 'scale(' + (zoomLevel / 100) + ') rotate(' + rotation + 'deg)';
            zoomLevelSpan.textContent = zoomLevel + '%';
        }

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
        window.image_viewer_basic_init = image_viewer_basic_init;
    }
})();
