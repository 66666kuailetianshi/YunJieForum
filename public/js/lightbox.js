/**
 * 云界论坛 - 图片灯箱（lightbox）
 * 点击带有 data-lightbox 的图片（.bbcode-image），全屏查看原图；
 * 支持上一张/下一张、Esc/点击遮罩关闭
 */
(function () {
    'use strict';

    var overlay = null;
    var images = [];
    var currentIndex = 0;

    function getAllImages() {
        return Array.prototype.slice.call(document.querySelectorAll('img[data-lightbox]'));
    }

    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'lightbox-overlay';
        overlay.style.display = 'none';

        var img = document.createElement('img');
        img.alt = '图片预览';
        overlay.appendChild(img);

        var closeBtn = document.createElement('button');
        closeBtn.className = 'lightbox-close';
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', '关闭');
        closeBtn.innerHTML = '&times;';
        overlay.appendChild(closeBtn);

        var counter = document.createElement('div');
        counter.className = 'lightbox-counter';
        overlay.appendChild(counter);

        var prevBtn = document.createElement('button');
        prevBtn.className = 'lightbox-nav lightbox-nav-prev';
        prevBtn.type = 'button';
        prevBtn.setAttribute('aria-label', '上一张');
        prevBtn.innerHTML = '&#8249;';
        overlay.appendChild(prevBtn);

        var nextBtn = document.createElement('button');
        nextBtn.className = 'lightbox-nav lightbox-nav-next';
        nextBtn.type = 'button';
        nextBtn.setAttribute('aria-label', '下一张');
        nextBtn.innerHTML = '&#8250;';
        overlay.appendChild(nextBtn);

        // 事件
        closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            close();
        });
        prevBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            show(currentIndex - 1);
        });
        nextBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            show(currentIndex + 1);
        });
        overlay.addEventListener('click', function () {
            close();
        });
        overlay.querySelector('img').addEventListener('click', function (e) {
            e.stopPropagation();
        });

        document.body.appendChild(overlay);
    }

    function show(index) {
        if (!images.length) return;
        if (index < 0) index = images.length - 1;
        if (index >= images.length) index = 0;
        currentIndex = index;

        var src = images[currentIndex].getAttribute('src') || '';
        var alt = images[currentIndex].getAttribute('alt') || '图片预览';
        var img = overlay.querySelector('img');
        img.src = src;
        img.alt = alt;

        if (images.length > 1) {
            var counter = overlay.querySelector('.lightbox-counter');
            counter.textContent = (currentIndex + 1) + ' / ' + images.length;
            counter.style.display = '';
            overlay.querySelector('.lightbox-nav-prev').style.display = '';
            overlay.querySelector('.lightbox-nav-next').style.display = '';
        } else {
            overlay.querySelector('.lightbox-counter').style.display = 'none';
            overlay.querySelector('.lightbox-nav-prev').style.display = 'none';
            overlay.querySelector('.lightbox-nav-next').style.display = 'none';
        }

        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function close() {
        if (!overlay) return;
        overlay.style.display = 'none';
        overlay.querySelector('img').src = '';
        document.body.style.overflow = '';
    }

    function open(index) {
        images = getAllImages();
        if (!images.length) return;
        if (!overlay) buildOverlay();
        show(index);
    }

    function onKeydown(e) {
        if (!overlay || overlay.style.display === 'none') return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft') show(currentIndex - 1);
        else if (e.key === 'ArrowRight') show(currentIndex + 1);
    }

    // 事件委托：点击预览图打开灯箱
    document.addEventListener('click', function (e) {
        var img = e.target.closest ? e.target.closest('img[data-lightbox]') : null;
        if (!img) return;
        var all = getAllImages();
        open(all.indexOf(img));
    });

    document.addEventListener('keydown', onKeydown);
})();
