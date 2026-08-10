/**
 * 云界论坛 - 编辑器图片上传辅助
 * 供 new_post.php / post.php 等使用 BBCode 的编辑器复用
 */
(function () {
    'use strict';

    // 复用同一个隐藏文件输入框，避免每次上传都向 DOM 追加节点，
    // 也防止用户取消选择时残留 input 元素
    var sharedFileInput = null;

    /**
     * 非阻塞错误提示（替代 alert，避免阻塞主线程）
     */
    function showError(message) {
        var div = document.createElement('div');
        div.textContent = message;
        div.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;background:#dc2626;color:#fff;padding:10px 18px;border-radius:8px;font-size:14px;box-shadow:0 4px 16px rgba(0,0,0,.25);max-width:80vw;word-break:break-all;';
        document.body.appendChild(div);
        setTimeout(function () {
            if (div.parentNode) div.parentNode.removeChild(div);
        }, 5000);
    }

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
            // 过滤可能破坏 BBCode 结构的字符，防止恶意 URL 注入伪造标签
            var safeUrl = String(url || '').replace(/\]|\[/g, '').trim();
            if (!safeUrl) return;
            this.insertAtCursor(textarea, '[img]' + safeUrl + '[/img]', '');
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

            // CSRF token 缺失时直接拒绝，防止请求无保护地被提交
            if (!csrfToken) {
                showError('安全令牌缺失，无法上传图片，请刷新页面后重试。');
                return;
            }

            // 复用共享文件输入框；首次使用时创建并缓存
            if (!sharedFileInput) {
                sharedFileInput = document.createElement('input');
                sharedFileInput.type = 'file';
                sharedFileInput.accept = 'image/jpeg,image/png,image/gif,image/webp';
                sharedFileInput.style.display = 'none';
                document.body.appendChild(sharedFileInput);
            }
            var input = sharedFileInput;

            input.addEventListener('change', function () {
                if (!input.files || !input.files[0]) {
                    input.value = '';
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
                formData.append('csrf_token', csrfToken);

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
                    showError('图片上传失败：' + err);
                })
                .catch(function (err) {
                    var pos = textarea.value.indexOf(placeholder);
                    if (pos !== -1) {
                        textarea.value = textarea.value.substring(0, pos) + '' + textarea.value.substring(pos + placeholder.length);
                    }
                    showError('图片上传失败：' + (err && err.message ? err.message : '网络错误，请重试。'));
                })
                .finally(function () {
                    input.value = ''; // 清空选择，确保同一文件可重复上传
                });
            });

            input.click();
        }
    };
})();
