/**
 * 云界论坛 - 前端交互脚本
 */

document.addEventListener('DOMContentLoaded', function () {
    // 移动端导航切换（抽屉作用在 #mainNav 上，与桌面端共用同一导航列表）
    const navToggle = document.getElementById('navToggle');
    const mainNav = document.getElementById('mainNav');

    if (navToggle && mainNav) {
        navToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = mainNav.classList.toggle('open');
            navToggle.setAttribute('aria-expanded', String(isOpen));
        });

        // 点击任意导航链接关闭抽屉
        mainNav.querySelectorAll('.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (mainNav.classList.contains('open')) {
                    mainNav.classList.remove('open');
                    navToggle.setAttribute('aria-expanded', 'false');
                }
            });
        });

        // 点击页面外部关闭菜单
        document.addEventListener('click', function (e) {
            if (!mainNav.contains(e.target) && !navToggle.contains(e.target) && mainNav.classList.contains('open')) {
                mainNav.classList.remove('open');
                navToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // 表单客户端校验
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            let valid = true;
            form.querySelectorAll('[required]').forEach(function (field) {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            if (!valid) {
                e.preventDefault();
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
            }
        });
    });

    // 输入框移除错误状态
    document.querySelectorAll('.form-control').forEach(function (input) {
        input.addEventListener('input', function () {
            input.classList.remove('is-invalid');
        });
    });

    // 提示框关闭按钮
    document.querySelectorAll('[data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const alert = btn.closest('[data-alert]');
            if (alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-8px)';
                alert.style.transition = 'all 0.25s ease';
                setTimeout(function () {
                    alert.remove();
                }, 250);
            }
        });
    });

    // 自动隐藏成功提示（4 秒后）
    document.querySelectorAll('.alert-success[data-alert]').forEach(function (alert) {
        setTimeout(function () {
            if (alert && alert.parentNode) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-8px)';
                alert.style.transition = 'all 0.25s ease';
                setTimeout(function () {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 250);
            }
        }, 4000);
    });

    // 帖子删除确认（使用 data-confirm 属性）
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            const message = el.getAttribute('data-confirm');
            if (!window.confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // 版块分类折叠/展开
    document.querySelectorAll('[data-collapse]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = btn.getAttribute('aria-controls');
            const target = targetId ? document.getElementById(targetId) : null;
            if (!target) return;

            const isExpanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', String(!isExpanded));
            target.classList.toggle('is-collapsed', isExpanded);
        });
    });

    // 用户下拉卡片（fixed 定位，完全由 JS 控制显隐，避免 hover/click 冲突）
    const userDropdown = document.querySelector('.user-dropdown');
    const userDropdownPanel = document.getElementById('userDropdownPanel');
    const userDropdownTrigger = userDropdown ? userDropdown.querySelector('.header-user') : null;

    if (userDropdown && userDropdownPanel && userDropdownTrigger) {
        let hideTimer = null;
        let isOpen = false;
        // 仅在支持精细指针悬停的设备上启用 hover 行为，避免移动端误触发
        const hoverable = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

        function positionPanel() {
            const rect = userDropdownTrigger.getBoundingClientRect();
            const maxPanelWidth = Math.min(280, window.innerWidth - 24);
            const panelWidth = Math.min(userDropdownPanel.offsetWidth || 280, maxPanelWidth);
            const panelHeight = userDropdownPanel.offsetHeight || 0;

            // 默认右对齐，紧贴触发器下方
            let left = rect.right - panelWidth;
            if (left < 12) left = 12;
            if (left + panelWidth > window.innerWidth - 12) {
                left = Math.max(12, window.innerWidth - panelWidth - 12);
            }

            let top = rect.bottom + 6;
            // 下方空间不足时显示在触发器上方
            if (panelHeight && top + panelHeight > window.innerHeight - 12) {
                top = Math.max(12, rect.top - panelHeight - 6);
            }

            userDropdownPanel.style.width = panelWidth + 'px';
            userDropdownPanel.style.top = top + 'px';
            userDropdownPanel.style.left = left + 'px';
        }

        function openPanel() {
            if (isOpen) return;
            isOpen = true;
            clearTimeout(hideTimer);
            positionPanel();
            userDropdownPanel.classList.add('is-visible');
            userDropdownTrigger.setAttribute('aria-expanded', 'true');
        }

        function closePanel() {
            if (!isOpen) return;
            isOpen = false;
            clearTimeout(hideTimer);
            userDropdownPanel.classList.remove('is-visible');
            userDropdownTrigger.setAttribute('aria-expanded', 'false');
        }

        function scheduleClose() {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(function () {
                if (isOpen) closePanel();
            }, 180);
        }

        function cancelClose() {
            clearTimeout(hideTimer);
        }

        if (hoverable) {
            userDropdown.addEventListener('mouseenter', openPanel);
            userDropdown.addEventListener('mouseleave', scheduleClose);
            userDropdownPanel.addEventListener('mouseenter', cancelClose);
            userDropdownPanel.addEventListener('mouseleave', scheduleClose);
        }

        // 点击触发器切换（同时阻止默认跳转，菜单内已提供个人中心入口）
        userDropdownTrigger.addEventListener('click', function (e) {
            e.preventDefault();
            if (isOpen) {
                closePanel();
            } else {
                openPanel();
            }
        });

        // 窗口尺寸变化或滚动时重新定位
        window.addEventListener('resize', function () {
            if (isOpen) positionPanel();
        });
        window.addEventListener('scroll', function () {
            if (isOpen) positionPanel();
        }, { passive: true });

        // 点击外部关闭
        document.addEventListener('click', function (e) {
            if (isOpen && !userDropdown.contains(e.target) && !userDropdownPanel.contains(e.target)) {
                closePanel();
            }
        });

        // ESC 键关闭
        document.addEventListener('keydown', function (e) {
            if (isOpen && e.key === 'Escape') closePanel();
        });
    }

});
