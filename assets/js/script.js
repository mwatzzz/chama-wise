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

        form.addEventListener('submit', (event) => {
            if (isSubmitting) {
                event.preventDefault();
                return;
            }

            isSubmitting = true;
            if (loadingState instanceof HTMLElement) {
                loadingState.hidden = false;
            }
            if (messages instanceof HTMLElement) {
                messages.setAttribute('aria-busy', 'true');
                messages.scrollTop = messages.scrollHeight;
            }
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = true;
                submitButton.textContent = 'Preparing answer…';
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
