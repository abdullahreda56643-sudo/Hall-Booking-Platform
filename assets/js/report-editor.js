(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const form = $('[data-report-editor]');
  if (!form) return;

  const title = $('#title', form);
  const className = $('[name="class_name"]', form);
  const beneficiariesCount = $('[name="beneficiaries_count"]', form);
  const body = $('#body', form);
  const saveStatuses = $$('[data-save-status], [data-save-status-bottom]', form);
  const countLabel = $('[data-media-count]', form);
  const uploadMessage = $('[data-upload-message]', form);
  const submitDialog = $('[data-submit-dialog]');
  const submittedDialog = $('[data-submitted-dialog]');
  const submitButtons = $$('button[type="submit"]', form);
  const limits = { image: 5 * 1024 * 1024, video: 50 * 1024 * 1024, file: 10 * 1024 * 1024 };
  const extensions = {
    image: new Set(['jpg', 'jpeg', 'png', 'webp']),
    video: new Set(['mp4', 'webm']),
    file: new Set(['pdf', 'docx', 'xlsx']),
  };
  const acceptedMimes = {
    image: new Set(['image/jpeg', 'image/png', 'image/webp']),
    video: new Set(['video/mp4', 'video/webm']),
    file: new Set([
      'application/pdf',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/zip',
    ]),
  };
  let reportId = Number(form.dataset.reportId) || null;
  let changed = false;
  let revision = 0;
  let saveTimer;
  let saveChain = Promise.resolve();
  let uploadChain = Promise.resolve();
  let submitAction = 'draft';
  let draggedItem;

  const toast = message => {
    const root = $('#toast-root');
    if (!root) return;
    const notice = document.createElement('div');
    notice.className = 'toast toast-error';
    notice.setAttribute('role', 'alert');
    notice.textContent = message;
    root.append(notice);
    window.setTimeout(() => notice.remove(), 6000);
  };
  const setStatus = (message, state = '') => {
    saveStatuses.forEach(status => {
      status.classList.toggle('is-saving', state === 'saving');
      status.classList.toggle('is-error', state === 'error');
      const text = document.createTextNode(message);
      status.replaceChildren(document.createElement('i'), text);
    });
  };
  const mediaItems = () => $$('.report-file-item', form);
  const updateCount = () => {
    const count = mediaItems().length;
    if (countLabel) countLabel.textContent = `${new Intl.NumberFormat('ar').format(count)} من 10 ملفات`;
    $$('[data-file-input]', form).forEach(input => { input.disabled = count >= 10; });
  };
  const resizeBody = () => {
    body.style.height = 'auto';
    body.style.height = `${Math.max(360, body.scrollHeight)}px`;
    $('[data-character-count]', form).textContent = new Intl.NumberFormat('ar').format(body.value.length);
  };
  const buildPayload = action => {
    const data = new FormData();
    data.set('csrf_token', form.dataset.csrf);
    data.set('title', title.value);
    data.set('class_name', className.value);
    data.set('beneficiaries_count', beneficiariesCount.value);
    data.set('body', body.value);
    data.set('save_action', action);
    if (reportId) data.set('report_id', String(reportId));
    return data;
  };
  const sendReportRequest = data => new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('POST', form.action);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.addEventListener('load', () => {
      let result;
      try {
        result = JSON.parse(request.responseText);
      } catch {
        reject(new Error('تعذر قراءة استجابة الحفظ من الخادم.'));
        return;
      }
      if (request.status >= 200 && request.status < 300 && result.ok) resolve(result);
      else reject(new Error(result.message || 'تعذر حفظ التقرير.'));
    });
    request.addEventListener('error', () => reject(new Error('تعذر الاتصال بالخادم أثناء حفظ التقرير.')));
    request.send(data);
  });
  const saveDraft = () => {
    window.clearTimeout(saveTimer);
    if (!changed && reportId) return saveChain;
    const currentRevision = revision;
    changed = false;
    setStatus('جارٍ حفظ المسودة…', 'saving');
    const request = saveChain.catch(() => {}).then(() => sendReportRequest(buildPayload('autosave')));
    saveChain = request;
    return request.then(result => {
      reportId = Number(result.report_id);
      form.dataset.reportId = String(reportId);
      $('[data-report-id-input]', form).value = String(reportId);
      if (revision === currentRevision) {
        setStatus('تم الحفظ قبل لحظات');
      } else {
        changed = true;
        saveTimer = window.setTimeout(() => { saveDraft().catch(() => {}); }, 0);
      }
      return result;
    }).catch(error => {
      changed = true;
      setStatus('تعذر حفظ المسودة', 'error');
      toast(error.message);
      throw error;
    });
  };
  const flushDraft = async () => {
    window.clearTimeout(saveTimer);
    if (!reportId) changed = true;
    await saveChain;
    while (changed || !reportId) await saveDraft();
  };
  const queueAutosave = () => {
    changed = true;
    revision += 1;
    setStatus('تغييرات غير محفوظة', 'saving');
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(() => { saveDraft().catch(() => {}); }, 850);
  };
  const extensionOf = file => file.name.split('.').pop().toLowerCase();
  const formatBytes = size => size >= 1024 * 1024
    ? `${(size / (1024 * 1024)).toFixed(2)} MB`
    : `${Math.max(1, Math.round(size / 1024))} KB`;
  const validateFile = (file, type) => {
    const extension = extensionOf(file);
    if (!extensions[type].has(extension) || (file.type && !acceptedMimes[type].has(file.type))) {
      const category = type === 'image' ? 'الصور' : type === 'video' ? 'الفيديو' : 'الملفات';
      return `نوع الملف ${file.name} غير مسموح في قسم ${category}.`;
    }
    if (file.size <= 0 || file.size > limits[type]) {
      return `حجم ${file.name} غير مسموح؛ الحد ${formatBytes(limits[type])}.`;
    }
    return '';
  };
  const apiRequest = (action, values = {}) => new Promise((resolve, reject) => {
    const data = new FormData();
    data.set('csrf_token', form.dataset.csrf);
    data.set('report_id', String(reportId));
    data.set('action', action);
    Object.entries(values).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach(item => data.append(`${key}[]`, String(item)));
      else data.set(key, String(value));
    });
    const request = new XMLHttpRequest();
    request.open('POST', form.dataset.uploadUrl);
    request.addEventListener('load', () => {
      let result;
      try {
        result = JSON.parse(request.responseText);
      } catch {
        reject(new Error('تعذر قراءة استجابة الخادم.'));
        return;
      }
      if (request.status >= 200 && request.status < 300 && result.ok) resolve(result);
      else reject(new Error(result.message || 'تعذرت معالجة المرفق.'));
    });
    request.addEventListener('error', () => reject(new Error('تعذر الاتصال بالخادم أثناء معالجة المرفق.')));
    request.send(data);
  });
  const createFileItem = (file, type) => {
    const item = document.createElement('article');
    item.className = 'report-file-item report-file-enter';
    item.draggable = true;
    item.dataset.localFile = 'true';
    item.dataset.mediaType = type;
    const thumbnail = document.createElement(type === 'image' ? 'img' : 'span');
    thumbnail.className = `report-file-thumbnail ${type === 'video' ? 'report-video-thumbnail' : ''} ${type === 'file' ? 'report-document-thumbnail' : ''}`;
    if (type === 'image') {
      thumbnail.alt = '';
      thumbnail.src = URL.createObjectURL(file);
    } else {
      thumbnail.textContent = type === 'video' ? '▶' : extensionOf(file).toUpperCase();
      thumbnail.setAttribute('aria-hidden', 'true');
    }
    const info = document.createElement('span');
    info.className = 'report-file-info';
    const name = document.createElement('strong');
    name.textContent = file.name;
    const status = document.createElement('small');
    status.textContent = `بانتظار الرفع · ${formatBytes(file.size)}`;
    info.append(name, status);
    const progress = document.createElement('span');
    progress.className = 'report-file-progress';
    const fill = document.createElement('i');
    progress.append(fill);
    const remove = document.createElement('button');
    remove.className = 'report-file-delete';
    remove.type = 'button';
    remove.setAttribute('aria-label', `حذف ${file.name}`);
    remove.textContent = '×';
    item.append(thumbnail, info, progress, remove);
    item._fileStatus = status;
    item._progressFill = fill;
    item._removeButton = remove;
    item._objectUrl = type === 'image' ? thumbnail.src : '';
    return item;
  };
  const persistOrder = () => {
    const items = mediaItems();
    const ids = items.map(item => item.dataset.existingMedia).filter(Boolean);
    if (!reportId || ids.length !== items.length) return;
    apiRequest('order', { order: ids }).catch(error => toast(error.message));
  };
  const uploadFile = async (item, file, type) => {
    if (!item.isConnected) return;
    try {
      await flushDraft();
      const data = new FormData();
      data.set('csrf_token', form.dataset.csrf);
      data.set('report_id', String(reportId));
      data.set('action', 'upload');
      data.append('media[]', file, file.name);
      item.classList.add('is-uploading');
      item._removeButton.disabled = true;
      item._fileStatus.textContent = 'جارٍ الرفع… 0%';
      await new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        request.open('POST', form.dataset.uploadUrl);
        request.upload.addEventListener('progress', event => {
          if (!event.lengthComputable) return;
          const percent = Math.round(event.loaded / event.total * 100);
          item._progressFill.style.width = `${percent}%`;
          item._fileStatus.textContent = `جارٍ الرفع… ${new Intl.NumberFormat('ar').format(percent)}٪`;
        });
        request.addEventListener('load', () => {
          let result;
          try {
            result = JSON.parse(request.responseText);
          } catch {
            reject(new Error('تعذر قراءة استجابة الخادم بعد رفع الملف.'));
            return;
          }
          if (request.status < 200 || request.status >= 300 || !result.ok || !result.media) {
            reject(new Error(result.message || `تعذر رفع ${file.name}.`));
            return;
          }
          item.dataset.existingMedia = result.media.id;
          item._fileStatus.textContent = `تم الرفع · ${formatBytes(file.size)}`;
          item.classList.remove('is-uploading', 'has-error');
          item.classList.add('is-uploaded');
          item._removeButton.disabled = false;
          item._progressFill.style.width = '100%';
          resolve();
        });
        request.addEventListener('error', () => reject(new Error(`تعذر الاتصال بالخادم أثناء رفع ${file.name}.`)));
        request.send(data);
      });
      persistOrder();
    } catch (error) {
      item.classList.remove('is-uploading');
      item.classList.add('has-error');
      item._removeButton.disabled = false;
      item._fileStatus.textContent = error.message;
      uploadMessage.textContent = error.message;
    }
    updateCount();
  };
  const enqueueFiles = (files, type) => {
    [...files].forEach(file => {
      const item = createFileItem(file, type);
      $(`[data-file-list="${type}"]`, form).append(item);
      const error = validateFile(file, type);
      if (error) {
        item._fileStatus.textContent = error;
        item.classList.add('has-error');
        uploadMessage.textContent = error;
        updateCount();
        return;
      }
      if (mediaItems().length > 10) {
        if (item._objectUrl) URL.revokeObjectURL(item._objectUrl);
        item.remove();
        uploadMessage.textContent = 'الحد الأقصى 10 ملفات لكل تقرير.';
        toast(uploadMessage.textContent);
        updateCount();
        return;
      }
      const task = uploadChain.then(() => uploadFile(item, file, type));
      uploadChain = task.catch(() => {});
      updateCount();
    });
  };
  const sendReport = async action => {
    submitButtons.forEach(button => { button.disabled = true; });
    try {
      await flushDraft();
      await uploadChain;
      if (mediaItems().some(item => item.classList.contains('has-error') && !item.dataset.existingMedia)) {
        throw new Error('أصلحي أخطاء المرفقات أو احذفي الملفات التي تعذّر رفعها قبل الحفظ.');
      }
      const result = await sendReportRequest(buildPayload(action));
      if (result.submitted && submittedDialog?.showModal) {
        submittedDialog.showModal();
        window.setTimeout(() => window.location.assign(result.redirect), 1100);
      } else if (result.redirect) {
        window.location.assign(result.redirect);
      }
    } catch (error) {
      submitButtons.forEach(button => { button.disabled = false; });
      toast(error.message);
    }
  };

  title.addEventListener('input', queueAutosave);
  className.addEventListener('input', queueAutosave);
  beneficiariesCount.addEventListener('input', queueAutosave);
  body.addEventListener('input', () => {
    resizeBody();
    queueAutosave();
  });
  resizeBody();
  $$('[data-file-input]', form).forEach(input => {
    const type = input.dataset.fileInput;
    const dropzone = $(`[data-dropzone="${type}"]`, form);
    input.addEventListener('change', () => {
      enqueueFiles(input.files, type);
      input.value = '';
    });
    dropzone.addEventListener('click', event => {
      if (!event.target.closest('input')) input.click();
    });
    dropzone.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        input.click();
      }
    });
    ['dragenter', 'dragover'].forEach(name => dropzone.addEventListener(name, event => {
      event.preventDefault();
      dropzone.classList.add('dragover');
    }));
    ['dragleave', 'drop'].forEach(name => dropzone.addEventListener(name, event => {
      event.preventDefault();
      dropzone.classList.remove('dragover');
    }));
    dropzone.addEventListener('drop', event => {
      if (event.dataTransfer?.files.length) enqueueFiles(event.dataTransfer.files, type);
    });
  });
  $$('[data-editor-action]', form).forEach(button => {
    button.addEventListener('click', () => {
      const start = body.selectionStart;
      const end = body.selectionEnd;
      const selected = body.value.slice(start, end);
      const insert = button.dataset.editorAction === 'bold'
        ? `**${selected || 'نص بارز'}**`
        : button.dataset.editorAction === 'list'
          ? (selected ? selected.split('\n').map(line => `• ${line}`).join('\n') : '• ')
          : `\n\nعنوان: ${selected || ''}\n`;
      body.setRangeText(insert, start, end, 'select');
      body.focus();
      resizeBody();
      queueAutosave();
    });
  });
  form.addEventListener('click', event => {
    const button = event.target.closest('[data-delete-media]');
    if (!button) return;
    const item = button.closest('.report-file-item');
    if (!item) return;
    const mediaId = item.dataset.existingMedia;
    if (!mediaId) {
      if (item._objectUrl) URL.revokeObjectURL(item._objectUrl);
      item.remove();
      updateCount();
      return;
    }
    button.disabled = true;
    apiRequest('delete', { media_id: mediaId }).then(result => {
      item.remove();
      if (item._objectUrl) URL.revokeObjectURL(item._objectUrl);
      if (result.warning) toast(result.warning);
      updateCount();
      persistOrder();
    }).catch(error => {
      button.disabled = false;
      toast(error.message);
    });
  });
  form.addEventListener('dragstart', event => {
    draggedItem = event.target.closest('.report-file-item');
    if (!draggedItem) return;
    event.dataTransfer.effectAllowed = 'move';
    draggedItem.classList.add('is-dragging');
  });
  form.addEventListener('dragend', () => {
    draggedItem?.classList.remove('is-dragging');
    draggedItem = null;
  });
  form.addEventListener('dragover', event => {
    const list = event.target.closest('.report-file-list');
    if (!draggedItem || !list || list !== draggedItem.parentElement) return;
    event.preventDefault();
    const after = [...list.querySelectorAll('.report-file-item:not(.is-dragging)')].find(item => {
      const rect = item.getBoundingClientRect();
      return event.clientY < rect.top + rect.height / 2;
    });
    list.insertBefore(draggedItem, after || null);
  });
  form.addEventListener('drop', event => {
    if (draggedItem && event.target.closest('.report-file-list')) persistOrder();
  });
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    submitAction = event.submitter?.value || 'draft';
    if (submitAction === 'submit') {
      if (typeof submitDialog.showModal === 'function') submitDialog.showModal();
      else if (!window.confirm('سيُقفل تعديل التقرير بعد إرساله للاعتماد. هل تريدين المتابعة؟')) return;
      else sendReport(submitAction);
      return;
    }
    sendReport(submitAction);
  });
  $('[data-submit-cancel]')?.addEventListener('click', () => submitDialog.close());
  $('[data-submit-confirm]')?.addEventListener('click', () => {
    submitDialog.close();
    sendReport(submitAction);
  });
  updateCount();
})();
