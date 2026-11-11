document.addEventListener('DOMContentLoaded', () => {
    const reportType = document.querySelector('#dashboard-report-type');
    const reportLink = document.querySelector('#dashboard-report-link');
    if (!reportType || !reportLink) {
        return;
    }

    reportType.addEventListener('change', () => {
        reportLink.href = `${reportLink.dataset.baseUrl}?report=${encodeURIComponent(reportType.value)}`;
    });
    reportLink.dataset.baseUrl = reportLink.href.split('?')[0];
});
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.ai-chat-form').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const loadingState = form.parentElement?.querySelector('.ai-loading-state');
        const messages = form.parentElement?.querySelector('.ai-chat-messages');
        const submitButton = form.querySelector('button[type="submit"]');
        let isSubmitting = false;

        form.addEventListener('submit', async (event) => {
            if (isSubmitting) {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            if (!(messages instanceof HTMLElement) || !(submitButton instanceof HTMLButtonElement)) {
                form.submit();
                return;
            }

            isSubmitting = true;
            const startedAt = performance.now();
            const formData = new FormData(form);
            const question = String(formData.get('question') ?? '').trim();
            const emptyState = messages.querySelector('.ai-empty-state');
            emptyState?.remove();
            messages.classList.remove('is-empty');
            messages.classList.add('has-messages');

            const appendMessage = (className, label, content) => {
                const wrapper = document.createElement('div');
                wrapper.className = `ai-message ${className}`;
                const heading = document.createElement('strong');
                heading.textContent = label;
                const paragraph = document.createElement('p');
                paragraph.textContent = content;
                const time = document.createElement('small');
                time.textContent = new Date().toLocaleTimeString();
                wrapper.append(heading, paragraph, time);
                messages.append(wrapper);
                return {wrapper, paragraph, time};
            };

            appendMessage('ai-message-member', 'You', question);
            const responseMessage = appendMessage('ai-message-assistant', 'ChamaWise Advisor', 'Checking your records…');
            responseMessage.paragraph.setAttribute('aria-live', 'off');
            let firstTokenAt = null;
            let completed = false;

            if (loadingState instanceof HTMLElement) {
                loadingState.hidden = false;
            }
            messages.setAttribute('aria-busy', 'true');
            messages.scrollTop = messages.scrollHeight;
            submitButton.disabled = true;
            submitButton.textContent = 'Advisor is responding…';

            const handleEvent = (rawEvent) => {
                let eventName = 'message';
                const dataLines = [];
                rawEvent.replace(/\r/g, '').split('\n').forEach((line) => {
                    if (line.startsWith('event:')) eventName = line.slice(6).trim();
                    if (line.startsWith('data:')) dataLines.push(line.slice(5).trimStart());
                });
                if (!dataLines.length) return;

                const data = JSON.parse(dataLines.join('\n'));
                if (eventName === 'start') {
                    const sessionField = form.querySelector('[name="session_id"]');
                    if (sessionField instanceof HTMLInputElement && data.session_id) {
                        sessionField.value = String(data.session_id);
                        const nextUrl = new URL(window.location.href);
                        nextUrl.searchParams.set('session', String(data.session_id));
                        window.history.replaceState(null, '', nextUrl);
                    }
                } else if (eventName === 'token') {
                    if (firstTokenAt === null) {
                        firstTokenAt = performance.now();
                        if (loadingState instanceof HTMLElement) loadingState.hidden = true;
                        responseMessage.paragraph.textContent = '';
                    }
                    responseMessage.paragraph.textContent += String(data.text ?? '');
                    messages.scrollTop = messages.scrollHeight;
                } else if (eventName === 'complete') {
                    completed = true;
                    if (loadingState instanceof HTMLElement) loadingState.hidden = true;
                    const firstResponseSeconds = firstTokenAt === null
                        ? null
                        : ((firstTokenAt - startedAt) / 1000).toFixed(1);
                    const totalSeconds = ((performance.now() - startedAt) / 1000).toFixed(1);
                    const serverTimings = [
                        Number.isFinite(data.bootstrap_ms) ? `setup ${(data.bootstrap_ms / 1000).toFixed(1)}s` : '',
                        Number.isFinite(data.context_ms) ? `records ${(data.context_ms / 1000).toFixed(1)}s` : '',
                        Number.isFinite(data.model_total_ms) ? `model ${(data.model_total_ms / 1000).toFixed(1)}s` : ''
                    ].filter(Boolean).join(' · ');
                    responseMessage.time.textContent = (firstResponseSeconds === null
                        ? `Complete · ${totalSeconds}s`
                        : `First text ${firstResponseSeconds}s · Complete ${totalSeconds}s`)
                        + (serverTimings ? ` · ${serverTimings}` : '');
                    const questionField = form.querySelector('[name="question"]');
                    if (questionField instanceof HTMLTextAreaElement) questionField.value = '';
                } else if (eventName === 'error') {
                    throw new Error(String(data.message || 'The AI advisor could not complete this response.'));
                }
            };

            try {
                const response = await fetch(form.action || window.location.href, {
                    method: 'POST',
                    headers: {
                        'Accept': 'text/event-stream',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData,
                    credentials: 'same-origin'
                });
                if (!response.ok) {
                    let message = 'The AI advisor request could not be completed.';
                    try {
                        const data = await response.json();
                        if (data.message) message = data.message;
                    } catch (_) {
                        // Keep the safe generic error when the server does not return JSON.
                    }
                    throw new Error(message);
                }

                if (response.body && response.body.getReader) {
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    while (true) {
                        const {value, done} = await reader.read();
                        if (done) break;
                        buffer += decoder.decode(value, {stream: true});
                        let boundary;
                        while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                            handleEvent(buffer.slice(0, boundary));
                            buffer = buffer.slice(boundary + 2);
                        }
                    }
                    buffer += decoder.decode();
                    if (buffer.trim()) handleEvent(buffer);
                } else {
                    const body = await response.text();
                    body.split(/\n\n/).forEach(handleEvent);
                }
                if (!completed) {
                    throw new Error('The connection ended before the advisor finished. Your question was saved, but no complete answer was received.');
                }
            } catch (error) {
                if (loadingState instanceof HTMLElement) loadingState.hidden = true;
                const errorMessage = error instanceof Error
                    ? error.message
                    : 'The AI advisor connection was interrupted. Please try again.';
                if (firstTokenAt === null) responseMessage.paragraph.textContent = '';
                const alert = document.createElement('p');
                alert.className = 'ai-message-error';
                alert.setAttribute('role', 'alert');
                alert.textContent = errorMessage;
                responseMessage.wrapper.append(alert);
            } finally {
                isSubmitting = false;
                messages.setAttribute('aria-busy', 'false');
                submitButton.disabled = false;
                submitButton.textContent = 'Send Question';
                messages.scrollTop = messages.scrollHeight;
            }
        });

    });

    const question = document.querySelector('#ai-question');
    if (!(question instanceof HTMLTextAreaElement)) {
        return;
    }

    document.querySelectorAll('[data-ai-prompt]').forEach((prompt) => {
        prompt.addEventListener('click', () => {
            if (prompt instanceof HTMLButtonElement) {
                question.value = prompt.dataset.aiPrompt ?? '';
                question.focus();
            }
        });
    });
    const messages = document.querySelector('.ai-chat-messages.has-messages');
    if (messages) {
        messages.scrollTop = messages.scrollHeight;
    }
});
document.addEventListener('DOMContentLoaded', () => {
    const meetingDate = document.querySelector('.meeting-date-input');
    const minutesFields = document.querySelector('[data-meeting-minutes-fields]');
    const minutesNotice = document.querySelector('[data-meeting-minutes-notice]');
    if (!meetingDate || !minutesFields || !minutesNotice) {
        return;
    }

    const updateMinutesAvailability = () => {
        const isAfterMeeting = meetingDate.value < meetingDate.dataset.today;
        minutesFields.hidden = !isAfterMeeting;
        minutesNotice.hidden = isAfterMeeting;
    };

    meetingDate.addEventListener('change', updateMinutesAvailability);
    updateMinutesAvailability();
});
document.addEventListener('DOMContentLoaded', () => {
    // Reserved for later dashboard behaviour. Bootstrap handles navigation.
});
document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.profile-tab');
    const sections = document.querySelectorAll('.profile-section');

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.profileTab;

            tabs.forEach((item) => {
                const isActive = item === tab;
                item.classList.toggle('active', isActive);
                item.setAttribute('aria-selected', String(isActive));
            });

            sections.forEach((section) => {
                const isMatch = section.dataset.profileSection === target;
                section.hidden = !isMatch;
                section.classList.toggle('active', isMatch);
            });
        });
    });

    const form = document.querySelector('[data-investment-calculator-form]');
    const result = document.querySelector('[data-investment-calculator-result]');
    const canvas = document.querySelector('[data-investment-calculator-chart]');
    if (form && result && canvas instanceof HTMLCanvasElement) {

        const context = canvas.getContext('2d');
        if (!context) {
            result.textContent = 'The calculator chart is unavailable in this browser.';
        } else {

            const currency = new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES' });
            const calculate = () => {
                const rawValues = [
                    form.elements.initial.value,
                    form.elements.monthly.value,
                    form.elements.annual_return.value,
                    form.elements.months.value,
                ];
                if (rawValues.some((value) => value.trim() === '')) {
                    result.textContent = 'Enter all assumptions to see an estimate.';
                    context.clearRect(0, 0, canvas.width, canvas.height);
                    return;
                }
                const initial = Number(rawValues[0]);
                const monthly = Number(rawValues[1]);
                const annualReturn = Number(rawValues[2]);
                const months = Number(rawValues[3]);
                if (!Number.isFinite(initial) || !Number.isFinite(monthly) ||
                    !Number.isFinite(annualReturn) || !Number.isInteger(months) ||
                    initial < 0 || initial > 1000000000 ||
                    monthly < 0 || monthly > 1000000000 || annualReturn < -100 ||
                    annualReturn > 100 || months < 1 || months > 600) {
                    result.textContent = 'Enter non-negative amounts, an annual assumption from -100% to 100%, and a whole duration from 1 to 600 months.';
                    context.clearRect(0, 0, canvas.width, canvas.height);
                    return;
                }

                const monthlyFactor = Math.pow(1 + annualReturn / 100, 1 / 12);
                let projected = initial;
                let contributed = initial;
                const estimate = [initial];
                const zeroReturn = [initial];
                for (let month = 1; month <= months; month += 1) {
                    projected = projected * monthlyFactor + monthly;
                    contributed += monthly;
                    estimate.push(projected);
                    zeroReturn.push(contributed);
                }
                const zeroValue = initial + monthly * months;
                result.textContent = `Total contributed: ${currency.format(zeroValue)}. Illustrative value at ${annualReturn}% assumed annual return: ${currency.format(projected)}. Estimated gain/loss: ${currency.format(projected - zeroValue)}. At 0% return: ${currency.format(zeroValue)}.`;

                const scale = window.devicePixelRatio || 1;
                const width = Math.max(320, canvas.clientWidth);
                const height = 260;
                canvas.width = Math.floor(width * scale);
                canvas.height = Math.floor(height * scale);
                context.setTransform(scale, 0, 0, scale, 0, 0);
                context.clearRect(0, 0, width, height);

                const padding = { top: 16, right: 16, bottom: 36, left: 76 };
                const plotWidth = width - padding.left - padding.right;
                const plotHeight = height - padding.top - padding.bottom;
                const maximum = Math.max(1, ...estimate, ...zeroReturn);
                const drawLine = (values, color) => {
                    context.beginPath();
                    context.strokeStyle = color;
                    context.lineWidth = 2;
                    values.forEach((value, index) => {
                        const x = padding.left + (index / months) * plotWidth;
                        const y = padding.top + plotHeight - (value / maximum) * plotHeight;
                        if (index === 0) {
                            context.moveTo(x, y);
                        } else {
                            context.lineTo(x, y);
                        }
                    });
                    context.stroke();
                };
                context.strokeStyle = '#9aa9a0';
                context.lineWidth = 1;
                context.beginPath();
                context.moveTo(padding.left, padding.top);
                context.lineTo(padding.left, padding.top + plotHeight);
                context.lineTo(padding.left + plotWidth, padding.top + plotHeight);
                context.stroke();
                drawLine(zeroReturn, '#87938b');
                drawLine(estimate, '#0b5c3a');
                context.fillStyle = '#24352b';
                context.font = '12px sans-serif';
                context.fillText('KES ' + Math.round(maximum).toLocaleString('en-KE'), 4, padding.top + 5);
                context.fillText('Month 0', padding.left, height - 10);
                context.fillText(`${months} months`, Math.max(padding.left, width - 80), height - 10);
                context.fillStyle = '#0b5c3a';
                context.fillRect(padding.left + 8, 6, 12, 3);
                context.fillStyle = '#24352b';
                context.fillText('Assumed return', padding.left + 24, 12);
                context.fillStyle = '#87938b';
                context.fillRect(padding.left + 145, 6, 12, 3);
                context.fillStyle = '#24352b';
                context.fillText('0% return', padding.left + 161, 12);
            };

            form.addEventListener('input', calculate);
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                calculate();
            });
            window.addEventListener('resize', calculate);
            calculate();
        }
    }

    const fileInput = document.querySelector('.profile-file-input');
    const fileNameLabel = document.querySelector('.profile-file-name');
    const uploadForm = document.querySelector('.profile-upload-form');
    if (!fileInput || !uploadForm) {
        return;
    }

    fileInput.addEventListener('change', () => {
        if (fileNameLabel) {
            fileNameLabel.textContent = fileInput.files?.[0]?.name ?? '';
        }
    });

    uploadForm.addEventListener('submit', (event) => {
        if (!fileInput.files || fileInput.files.length === 0) {
            event.preventDefault();
            if (fileNameLabel) {
                fileNameLabel.textContent = 'Please choose a picture first.';
            }
        }
    });
});
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-daraja-payment-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            const status = form.querySelector('.daraja-payment-status');
            if (button instanceof HTMLButtonElement) {
                button.disabled = true;
                button.textContent = 'Sending sandbox request…';
            }
            if (status) {
                status.textContent = 'Contacting Daraja Sandbox. Do not submit again.';
            }
        });
    });
});
