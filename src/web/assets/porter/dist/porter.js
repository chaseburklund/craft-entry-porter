(function () {
    'use strict';

    // --- Entry edit screen: Copy JSON -------------------------------------
    //
    // Clicks are delegated from the document because the button can be re-rendered (for
    // example in element editor slideouts) without this script running again. The entry is
    // read from the clicked button's own container, since a slideout and the page beneath it
    // can both have one.
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest && e.target.closest('#porter-copy');
        if (!btn) {
            return;
        }
        var container = btn.closest('#porter-buttons');
        if (!container) {
            return;
        }
        handleCopyClick(
            container.dataset.entryId,
            container.dataset.siteId,
            container.dataset.provisional === '1',
            container.dataset.namedDraft === '1'
        );
    });

    async function handleCopyClick(entryId, siteId, isProvisionalDraft, isNamedDraftOfCanonical) {
        let res;
        try {
            res = await Craft.sendActionRequest('GET', 'entry-porter/porter/export', {
                params: { entryId: entryId, siteId: siteId },
            });
        } catch (e) {
            Craft.cp.displayError('Porter export failed: ' + porterError(e));
            return;
        }

        var data = res.data;
        var report = data.report || { warnings: [], unresolved: [], resolvedByFallback: [] };
        var json = JSON.stringify(data, null, 2);

        // A payload with no entry type will be refused on import, so report it as an error.
        var entryTypeUnresolved = data.entry && data.entry.typeHandle == null;
        if (entryTypeUnresolved) {
            var reason = findWarning(report.warnings, /entry type could not be resolved/i)
                || 'its entry type could not be resolved on the source environment.';
            // Copy it anyway, in case it is wanted for inspection.
            await copyToClipboardBestEffort(json);
            Craft.cp.displayError('This entry exported to an incomplete payload that will be refused on import: ' + reason);
            report.warnings.forEach(function (w) { console.warn('[Entry Porter]', w); });
            return;
        }

        var copied = await copyToClipboardBestEffort(json);
        var provisionalNote = isProvisionalDraft
            ? ' This is the last SAVED version -- unsaved edits in this session are not included.'
            : '';
        var namedDraftNote = isNamedDraftOfCanonical
            ? ' This copied the PUBLISHED entry -- this draft\'s own edits are not included, since Porter always exports the canonical entry.'
            : '';
        var warningNote = report.warnings.length
            ? ' Warning: ' + report.warnings[0] + (report.warnings.length > 1 ? ' (+' + (report.warnings.length - 1) + ' more, see console)' : '')
            : '';
        if (copied) {
            Craft.cp.displayNotice('Entry JSON copied to clipboard.' + provisionalNote + namedDraftNote + warningNote);
        } else {
            // The export succeeded; only the clipboard write failed, and the JSON was offered
            // in a dialog instead.
            Craft.cp.displayNotice('Entry exported, but the browser blocked the automatic clipboard copy -- use the dialog to copy it manually.' + provisionalNote + namedDraftNote + warningNote);
        }
        report.warnings.forEach(function (w) { console.warn('[Entry Porter]', w); });
    }

    async function copyToClipboardBestEffort(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (e) {
            window.prompt('Clipboard access was blocked. Copy this JSON manually (select all, then Ctrl/Cmd+C), then close this dialog:', text);
            return false;
        }
    }

    function findWarning(warnings, pattern) {
        for (var i = 0; i < (warnings || []).length; i++) {
            if (pattern.test(warnings[i])) {
                return warnings[i];
            }
        }
        return null;
    }

    // --- Utility: Paste JSON ----------------------------------------------
    //
    // Delegated for the same reason: the utility's markup is replaced when the user switches
    // between utilities.
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest && e.target.closest('#porter-import-btn');
        if (!btn) {
            return;
        }
        var container = btn.closest('#porter-utility') || document;
        handleImportClick(btn, container);
    });

    async function handleImportClick(importBtn, container) {
        var box = container.querySelector('#porter-json');
        var out = container.querySelector('#porter-result');
        if (!box || !out) {
            return;
        }
        let payload;
        try {
            payload = JSON.parse(box.value);
        } catch (e) {
            Craft.cp.displayError('That is not valid JSON.');
            return;
        }
        importBtn.classList.add('loading');
        try {
            const res = await Craft.sendActionRequest('POST', 'entry-porter/porter/import', { data: payload });
            const r = res.data;
            const report = r.report || { warnings: [], unresolved: [], resolvedByFallback: [] };
            out.innerHTML =
                '<p class="porter-ok">' + escapeHtml(pathSummary(r)) + editLinkHtml(r.cpEditUrl) + '</p>' +
                renderList('Warnings', report.warnings) +
                renderList('Unresolved references (dropped)', report.unresolved.map(refLabel)) +
                renderList('Resolved by fallback', report.resolvedByFallback.map((f) => refLabel(f.ref) + ' (' + f.how + ')'));
            Craft.cp.displayNotice('Imported as draft.');
        } catch (e) {
            out.innerHTML = '';
            Craft.cp.displayError('Porter import failed: ' + porterError(e));
        } finally {
            importBtn.classList.remove('loading');
        }
    }

    // One message per Importer path. Updating an earlier import's unapplied draft replaces
    // that draft's content, so it is not described as creating anything.
    var PATH_SUMMARIES = {
        'created': 'Created new draft.',
        'new-draft-of-canonical': 'Created draft of existing entry.',
        'updated-pending-draft': 'Updated the draft left by an earlier, still-unapplied import of this entry (that draft\'s previous content was replaced).',
    };
    function pathSummary(r) {
        return PATH_SUMMARIES[r.path] || (r.created ? 'Created new draft.' : 'Created draft of existing entry.');
    }

    function editLinkHtml(url) {
        if (!url) {
            return '';
        }
        return ' <a href="' + escapeHtml(url) + '">Open draft</a>';
    }

    function renderList(title, items) {
        if (!items || !items.length) return '';
        return '<h3>' + escapeHtml(title) + '</h3><ul>' + items.map((i) => '<li>' + escapeHtml(String(i)) + '</li>').join('') + '</ul>';
    }
    function refLabel(ref) {
        if (!ref || typeof ref !== 'object') return String(ref);
        return (ref.kind || '?') + ' ' + (ref.title || ref.uid || '');
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    }
    function porterError(e) {
        return (e && e.response && e.response.data && (e.response.data.message || e.response.data.error)) || (e && e.message) || 'unknown error';
    }
})();
