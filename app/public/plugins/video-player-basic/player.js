(function() {
    'use strict';

    function video_player_basic_init(options) {
        var container = options.container;
        var file = options.file;
        var config = options.config || {};

        var autoplayAttr = config.autoplay ? ' autoplay' : '';
        var controlsAttr = config.controls !== false ? ' controls' : '';

        container.innerHTML = `
            <div class="video-player-basic">
                <div class="video-player-basic-wrapper">
                    <video id="vpb-video" class="video-player-basic-video"${controlsAttr}${autoplayAttr}>
                        <source src="/media/${file.id}/preview" type="${file.mime_type || 'video/mp4'}">
                        Tu navegador no soporta el elemento de video.
                    </video>
                </div>
                <div class="video-player-basic-info">
                    <span class="video-player-basic-filename">${escapeHtml(file.name)}</span>
                    <a class="video-player-basic-download" href="/files/${file.id}/download">&#x2193; Descargar</a>
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
        window.video_player_basic_init = video_player_basic_init;
    }
})();
