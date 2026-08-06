/**
 * 云界论坛 - 编辑器图片上传辅助
 * 供 new_post.php / post.php 等使用 BBCode 的编辑器复用
 */
(function () {
    'use strict';

    window.EditorUpload = {
        /**
         * 在指定 textarea 光标处插入文本
         */
        insertAtCursor: function(textarea, textBefore, textAfter) {
            if (!textarea) return;
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var selected = textarea.value.substring(start, end);
            var replacement = textBefore + selected + textAfter;
            textarea.value = textarea.value.substring(0, start) + replacement + textarea.value.substring(end);
            textarea.focus();
            var cursorPos = start + textBefore.length + selected.length;
            textarea.setSelectionRange(cursorPos, cursorPos);
        },

        /**
         * 在光标处插入 [img]url[/img]
         */
        insertImageTag: function(textarea, url) {
            this.insertAtCursor(textarea, '[img]' + url + '[/img]', '');
        },

        /**
         * 触发图片本地上传
         * @param {string|HTMLTextAreaElement} target 文本框 id 或元素
         * @param {string} csrfToken
         * @param {string} uploadUrl 上传接口地址，默认 api/upload_image.php
         */
        uploadLocalImage: function(target, csrfToken, uploadUrl) {
            var textarea = typeof target === 'string' ? document.getElementById(target) : target;
            if (!textarea) return;

            var input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/jpeg,image/png,image/gif,image/webp';
            input.style.display = 'none';
            document.body.appendChild(input);

            input.addEventListener('change', function () {
                if (!input.files || !input.files[0]) {
                    document.body.removeChild(input);
                    return;
                }
                var file = input.files[0];

                var placeholder = '[上传图片中…]';
                var start = textarea.selectionStart;
                var end = textarea.selectionEnd;
                textarea.value = textarea.value.substring(0, start) + placeholder + textarea.value.substring(end);
                textarea.setSelectionRange(start + placeholder.length, start + placeholder.length);

                var formData = new FormData();
                formData.append('image', file);
                formData.append('csrf_token', csrfToken || '');

                fetch(uploadUrl || '/index.php?route=api/upload_image', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function (res) {
                    // 先按文本读取，再手动解析 JSON：
                    // 服务器若返回非 JSON（如 PHP 警告/报错被 display_errors 输出），
                    // 直接 res.json() 会抛错并被统一吞成"请重试"，导致无法定位真实原因
                    return res.text().then(function (text) {
                        var data = null;
                        try { data = JSON.parse(text); } catch (e) { data = null; }
                        return { ok: res.ok, status: res.status, data: data, text: text };
                    });
                })
                .then(function (result) {
                    var data = result.data;
                    var pos = textarea.value.indexOf(placeholder);
                    if (data && data.success && data.url) {
                        var tag = '[img]' + data.url + '[/img]';
                        if (pos !== -1) {
                            textarea.value = textarea.value.substring(0, pos) + tag + textarea.value.substring(pos + placeholder.length);
                            textarea.setSelectionRange(pos + tag.length, pos + tag.length);
                        } else {
                            EditorUpload.insertImageTag(textarea, data.url);
                        }
                        return;
                    }
                    // 失败时优先展示服务器返回的错误；响应非 JSON 时展示状态码与响应片段，便于定位
                    var err = (data && data.error) ? data.error
                        : (result.text ? 'HTTP ' + result.status + '：' + result.text.slice(0, 200)
                                       : 'HTTP ' + result.status + '，响应为空');
                    if (pos !== -1) {
                        textarea.value = textarea.value.substring(0, pos) + '' + textarea.value.substring(pos + placeholder.length);
                    }
                    alert('图片上传失败：' + err);
                })
                .catch(function (err) {
                    var pos = textarea.value.indexOf(placeholder);
                    if (pos !== -1) {
                        textarea.value = textarea.value.substring(0, pos) + '' + textarea.value.substring(pos + placeholder.length);
                    }
                    alert('图片上传失败：' + (err && err.message ? err.message : '网络错误，请重试。'));
                })
                .finally(function () {
                    try { document.body.removeChild(input); } catch (e) {}
                });
            });

            input.click();
        }
    };
})();
