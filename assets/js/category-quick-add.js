/**
 * 轩宇-导航 · 统一「新建分类 / 新建分组」组件（v2.12.0）
 *
 * 后台分类管理、后台导航管理筛选下拉、前台首页分类栏、我的导航页
 * 共用同一个弹窗、同一套字段和同一套校验规则，后端统一走
 * api/category_create.php，避免各界面各写一套、规则逐渐漂移。
 *
 * 用法：
 *   XYCategoryQuickAdd.open({
 *       mode: 'admin' | 'front',
 *       title: '新建分类',
 *       term: '分类' | '分组',
 *       apiUrl: '../api/category_create.php',   // 可选，默认按页面位置推断
 *       parents: [{id: 1, name: 'AI 工具'}],   // 仅 admin 有效
 *       users: [{id: 1, username: 'admin'}],   // 仅 admin 有效
 *       onCreated: function (item) { ... }
 *   });
 *
 *   XYCategoryQuickAdd.validateName(raw) → {ok, value, error}
 *
 * v2.14.0：提交时会按 mode 自动带上 scope=front|admin。前台页面即使同时存在
 * 后台管理员会话，接口也会把新建分类强制归属当前前台用户，不会落成公共分类。
 */
(function (window, document) {
    'use strict';

    var overlay = null;
    var dialog = null;
    var els = {};
    var config = {};
    var lastFocused = null;
    var toastTimer = null;

    /** 与后端 xycqCleanName() 保持一致的清洗规则 */
    function cleanName(raw) {
        var name = String(raw == null ? '' : raw).trim();
        name = name.replace(/[\r\n\t]/g, ' ');
        name = name.replace(/[\x00-\x1f\x7f]/g, '');

        var chars = Array.from ? Array.from(name) : name.split('');
        if (chars.length > 30) {
            name = chars.slice(0, 30).join('');
        }

        return name.trim();
    }

    function charLength(value) {
        return Array.from ? Array.from(value).length : value.length;
    }

    /** 与后端 api/category_create.php 完全一致的校验规则 */
    function validateName(raw) {
        var value = cleanName(raw);
        var length = charLength(value);

        if (value === '' || length < 2 || length > 30) {
            return { ok: false, value: value, error: '名称长度需为 2 ~ 30 个字符' };
        }

        if (/[<>]/.test(value)) {
            return { ok: false, value: value, error: '名称不能包含 < 或 >' };
        }

        return { ok: true, value: value, error: '' };
    }

    function defaultApiUrl() {
        return location.pathname.indexOf('/admin/') !== -1
            ? '../api/category_create.php'
            : 'api/category_create.php';
    }

    function build() {
        overlay = document.createElement('div');
        overlay.className = 'xycq-overlay';
        overlay.hidden = true;

        dialog = document.createElement('div');
        dialog.className = 'xycq-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'xycqTitle');

        // 骨架是纯静态 HTML，所有动态文本后续用 textContent 填入
        dialog.innerHTML =
            '<div class="xycq-head">' +
                '<h3 id="xycqTitle">新建分组</h3>' +
                '<button type="button" class="xycq-close" id="xycqClose" aria-label="关闭">&times;</button>' +
            '</div>' +
            '<div class="xycq-body">' +
                '<p class="xycq-note" id="xycqNote"></p>' +
                '<div class="xycq-field">' +
                    '<label class="xycq-label" for="xycqName">名称 <span class="xycq-req">*</span></label>' +
                    '<input type="text" class="xycq-input" id="xycqName" maxlength="30" autocomplete="off">' +
                '</div>' +
                '<div class="xycq-field" id="xycqParentField" hidden>' +
                    '<label class="xycq-label" for="xycqParent">上级分类</label>' +
                    '<select class="xycq-input" id="xycqParent"></select>' +
                '</div>' +
                '<div class="xycq-field" id="xycqOwnerField" hidden>' +
                    '<label class="xycq-label" for="xycqOwner">归属用户</label>' +
                    '<select class="xycq-input" id="xycqOwner"></select>' +
                '</div>' +
                '<label class="xycq-check">' +
                    '<input type="checkbox" id="xycqPrivate">' +
                    '<span id="xycqPrivateLabel">私密（仅本人可见）</span>' +
                '</label>' +
                '<div class="xycq-error" id="xycqError" role="alert" hidden></div>' +
            '</div>' +
            '<div class="xycq-foot">' +
                '<button type="button" class="xycq-btn" id="xycqCancel">取消</button>' +
                '<button type="button" class="xycq-btn is-primary" id="xycqSubmit">创建</button>' +
            '</div>';

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        ['Title', 'Note', 'Close', 'Name', 'ParentField', 'Parent', 'OwnerField', 'Owner',
            'Private', 'PrivateLabel', 'Error', 'Cancel', 'Submit'].forEach(function (key) {
            els[key] = document.getElementById('xycq' + key);
        });

        els.Close.addEventListener('click', close);
        els.Cancel.addEventListener('click', close);
        els.Submit.addEventListener('click', submit);

        overlay.addEventListener('mousedown', function (event) {
            if (event.target === overlay) {
                close();
            }
        });

        els.Name.addEventListener('input', function () {
            hideError();
        });

        els.Name.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submit();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!overlay || overlay.hidden) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                return;
            }

            // 简单焦点陷阱：Tab 只在弹窗内循环
            if (event.key === 'Tab') {
                var focusable = dialog.querySelectorAll('button:not([disabled]), input:not([hidden]), select:not([hidden])');
                var visible = Array.prototype.filter.call(focusable, function (node) {
                    return node.offsetParent !== null;
                });

                if (!visible.length) {
                    return;
                }

                var first = visible[0];
                var last = visible[visible.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    }

    function fillSelect(select, items, firstLabel, valueKey, labelKey) {
        select.innerHTML = '';

        var first = document.createElement('option');
        first.value = '0';
        first.textContent = firstLabel;
        select.appendChild(first);

        (items || []).forEach(function (item) {
            var option = document.createElement('option');
            option.value = String(item[valueKey]);
            option.textContent = String(item[labelKey]);
            select.appendChild(option);
        });
    }

    function showError(message) {
        els.Error.textContent = message;
        els.Error.hidden = false;
    }

    function hideError() {
        els.Error.hidden = true;
        els.Error.textContent = '';
    }

    function setBusy(busy) {
        els.Submit.disabled = busy;
        els.Submit.textContent = busy ? '创建中…' : '创建';
    }

    function open(options) {
        if (!overlay) {
            build();
        }

        config = options || {};
        var isAdmin = config.mode === 'admin';
        var term = config.term || (isAdmin ? '分类' : '分组');

        lastFocused = document.activeElement;

        els.Title.textContent = config.title || ('新建' + term);
        els.Note.textContent = isAdmin
            ? '使用全站统一的字段与校验规则；同名' + term + '会直接复用，不会重复创建。'
            : '新' + term + '只属于你自己；公共分类请由管理员在后台维护。';
        els.Name.setAttribute('placeholder', term + '名称，如：' + (isAdmin ? 'AI 工具' : '工作'));
        els.Name.value = '';
        els.Private.checked = false;
        els.PrivateLabel.textContent = isAdmin
            ? '私密' + term + '（仅归属用户本人可见）'
            : '私密' + term + '（仅本人可见，分享时不包含）';
        els.Submit.textContent = '创建';
        els.Submit.disabled = false;
        hideError();

        els.ParentField.hidden = !isAdmin || !config.parents || !config.parents.length;
        els.OwnerField.hidden = !isAdmin || !config.users || !config.users.length;

        fillSelect(els.Parent, config.parents || [], '无（顶级分类）', 'id', 'name');
        fillSelect(els.Owner, config.users || [], '公共（所有用户可见）', 'id', 'username');

        overlay.hidden = false;
        document.body.style.overflow = 'hidden';

        // 等一帧再聚焦，避免部分浏览器在 hidden 切换时丢焦点
        window.requestAnimationFrame(function () {
            els.Name.focus();
        });
    }

    function close() {
        if (!overlay || overlay.hidden) {
            return;
        }

        overlay.hidden = true;
        document.body.style.overflow = '';

        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    function showToast(message, isError) {
        var toast = document.getElementById('xycqToast');

        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'xycqToast';
            toast.className = 'xycq-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.className = 'xycq-toast' + (isError ? ' is-error' : '');
        window.clearTimeout(toastTimer);

        window.requestAnimationFrame(function () {
            toast.classList.add('is-visible');
        });

        toastTimer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 3200);
    }

    function submit() {
        if (els.Submit.disabled) {
            return;
        }

        var checked = validateName(els.Name.value);

        if (!checked.ok) {
            showError(checked.error);
            els.Name.focus();
            return;
        }

        hideError();
        setBusy(true);

        var form = new FormData();
        form.append('name', checked.value);
        // v2.14.0：显式声明调用来源。前台页面即使同时存在后台会话，也必须建成本人分组。
        form.append('scope', config.mode === 'admin' ? 'admin' : 'front');
        form.append('parent_id', els.Parent && !els.ParentField.hidden ? (els.Parent.value || '0') : '0');
        form.append('user_id', els.Owner && !els.OwnerField.hidden ? (els.Owner.value || '0') : '0');
        form.append('is_private', els.Private.checked ? '1' : '0');

        var apiUrl = config.apiUrl || defaultApiUrl();

        window.fetch(apiUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('接口返回的不是有效 JSON（HTTP ' + response.status + '）');
            });
        }).then(function (result) {
            setBusy(false);

            if (!result.ok) {
                showError(result.error || '创建失败，请稍后重试');
                return;
            }

            close();
            showToast(result.reused
                ? '已存在同名' + (config.term || '分组') + '，直接复用'
                : '已创建「' + result.name + '」');

            if (typeof config.onCreated === 'function') {
                config.onCreated(result);
            }
        }).catch(function (error) {
            setBusy(false);
            showError('请求失败：' + (error && error.message ? error.message : '网络异常'));
        });
    }

    window.XYCategoryQuickAdd = {
        open: open,
        close: close,
        validateName: validateName,
        cleanName: cleanName
    };
})(window, document);
