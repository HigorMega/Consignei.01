const statusMessage = document.getElementById('statusMessage');
const trialBadge = document.getElementById('trialBadge');
const btnAssinar = document.getElementById('btnAssinar');
const btnAltPayment = document.getElementById('btnAltPayment');
const paymentAlert = document.getElementById('paymentAlert');
const paymentAlertText = document.getElementById('paymentAlertText');
const btnAlertPayment = document.getElementById('btnAlertPayment');
const faturasContent = document.getElementById('faturasContent');
const planDescription = document.getElementById('planDescription');
const payerFirstNameInput = document.getElementById('payerFirstName');
const payerLastNameInput = document.getElementById('payerLastName');
const redirectEndpoint = '/api/billing_create_checkout.php?redirect=1';
const altRedirectEndpoint = '/api/checkout_create.php?redirect=1';
const REDIRECT_FALLBACK_DELAY = 2000;
let redirectNotice = null;
let redirectTimeout = null;
let altPaymentsEnabled = true;
let altPaymentsDefault = 'all';

const getPayerPayload = () => {
    const firstName = payerFirstNameInput?.value?.trim() || '';
    const lastName = payerLastNameInput?.value?.trim() || '';
    return { firstName, lastName };
};

const buildAltRedirectUrl = (method, payer) => {
    const params = new URLSearchParams();
    params.set('redirect', '1');
    params.set('method', method || 'all');
    if (payer.firstName) params.set('payer_first_name', payer.firstName);
    if (payer.lastName) params.set('payer_last_name', payer.lastName);
    return `/api/checkout_create.php?${params.toString()}`;
};

const ensureRedirectNotice = () => {
    if (redirectNotice) return redirectNotice;
    redirectNotice = document.createElement('p');
    redirectNotice.className = 'redirect-notice is-hidden';
    redirectNotice.innerHTML = `Se não abrir automaticamente, <a href="${redirectEndpoint}">clique aqui</a>.`;
    if (btnAssinar && btnAssinar.parentNode) {
        btnAssinar.parentNode.insertBefore(redirectNotice, btnAssinar.nextSibling);
    }
    return redirectNotice;
};

const readJsonResponse = async (response) => {
    const text = await response.text();
    if (!text) {
        throw new Error('Resposta vazia do servidor.');
    }
    try {
        return JSON.parse(text);
    } catch (error) {
        throw new Error('Resposta inválida do servidor.');
    }
};

const formatDate = (value) => {
    if (!value) return '--';
    if (value.includes('/')) {
        return value.split(' ')[0];
    }
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleDateString('pt-BR');
};

const formatDateTime = (value) => {
    if (!value) return '--';
    if (value.includes('/')) {
        return value;
    }
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleString('pt-BR');
};

const renderFaturas = (faturas) => {
    if (!Array.isArray(faturas) || faturas.length === 0) {
        faturasContent.textContent = 'Nenhuma fatura encontrada.';
        return;
    }

    const rows = faturas.map((fatura) => {
        const statusClass = fatura.status === 'paid'
            ? 'status-paid'
            : (fatura.status === 'failed' ? 'status-failed' : 'status-pending');
        return `
            <tr>
                <td><span class="status-pill ${statusClass}">${fatura.status}</span></td>
                <td>R$ ${Number(fatura.amount).toFixed(2)}</td>
                <td>${formatDate(fatura.period_start)} - ${formatDate(fatura.period_end)}</td>
                <td>${formatDateTime(fatura.paid_at || fatura.created_at)}</td>
                <td>${fatura.mp_payment_id || '-'}</td>
            </tr>
        `;
    }).join('');

    faturasContent.innerHTML = `
        <table class="faturas-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Valor</th>
                    <th>Período</th>
                    <th>Data</th>
                    <th>Pagamento</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    `;
};

const carregarFaturas = async () => {
    try {
        const response = await fetch('../api/assinatura_faturas.php', { credentials: 'include' });
        if (!response.ok) {
            throw new Error('Falha ao carregar faturas.');
        }
        const data = await readJsonResponse(response);
        renderFaturas(data.faturas || []);
    } catch (error) {
        faturasContent.textContent = 'Não foi possível carregar as faturas.';
    }
};

const openAltPayment = async (method) => {
    const normalizedMethod = method || 'all';
    const payer = getPayerPayload();
    const redirectUrl = buildAltRedirectUrl(normalizedMethod, payer);
    const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

    if (isMobile) {
        window.location.href = redirectUrl;
        return;
    }

    const win = window.open('about:blank', '_blank', 'noopener,noreferrer');
    if (!win) {
        window.location.href = redirectUrl;
        return;
    }

    try {
        const response = await fetch(`/api/checkout_create.php?method=${encodeURIComponent(normalizedMethod)}`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                method: normalizedMethod,
                payer_first_name: payer.firstName,
                payer_last_name: payer.lastName,
            }),
        });
        if (!response.ok) {
            throw new Error('Falha ao iniciar pagamento.');
        }
        const data = await readJsonResponse(response);
        const initPoint = data.init_point || data.checkout_url;
        if (!data.success || !initPoint) {
            throw new Error('Link de pagamento inválido.');
        }
        win.location = initPoint;
    } catch (error) {
        win.close();
        window.location.href = redirectUrl;
    }
};

const atualizarAlertaPagamento = (status) => {
    if (!paymentAlert) return;
    if (!status || !altPaymentsEnabled) {
        paymentAlert.classList.add('is-hidden');
        return;
    }

    if (status.last_payment_status === 'failed' && status.payment_suggestion === 'alt_payments') {
        paymentAlert.classList.remove('is-hidden');
        if (paymentAlertText) {
            paymentAlertText.textContent = status.payment_suggestion_text
                || status.message
                || 'Pagamento recusado. Recomendamos PIX/Boleto.';
        }
        return;
    }

    paymentAlert.classList.add('is-hidden');
};

const atualizarStatus = (status) => {
    if (!statusMessage) return;

    if (status.active) {
        statusMessage.textContent = 'Assinatura ativa. Acesso liberado ao painel.';
        btnAssinar.textContent = 'Ir para o dashboard';
        btnAssinar.onclick = () => {
            window.location.href = '/public/dashboard.html';
        };
        return;
    }

    statusMessage.textContent = 'Assinatura inativa. Ative para continuar usando o painel.';
    btnAssinar.textContent = 'Ativar assinatura (Cartão – recorrente)';
    btnAssinar.onclick = async () => {
        btnAssinar.disabled = true;
        btnAssinar.textContent = 'Redirecionando...';
        const notice = ensureRedirectNotice();
        notice.classList.add('is-hidden');
        if (redirectTimeout) {
            clearTimeout(redirectTimeout);
        }
        const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        if (isMobile) {
            window.location.href = redirectEndpoint;
            return;
        }

        const win = window.open('about:blank', '_blank', 'noopener,noreferrer');
        if (!win) {
            window.location.href = redirectEndpoint;
            return;
        }

        redirectTimeout = setTimeout(() => {
            notice.classList.remove('is-hidden');
        }, REDIRECT_FALLBACK_DELAY);

        try {
            const response = await fetch('/api/billing_create_checkout.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                },
            });
            if (!response.ok) {
                throw new Error('Falha ao iniciar pagamento.');
            }
            const data = await readJsonResponse(response);
            const initPoint = data.init_point || data.checkout_url;
            if (!data.success || !initPoint) {
                throw new Error('Link de pagamento inválido.');
            }
            win.location = initPoint;
        } catch (error) {
            win.close();
            window.location.href = redirectEndpoint;
        }
    };
};

const carregarStatus = async () => {
    try {
        const response = await fetch('../api/assinatura_status.php', { credentials: 'include' });
        if (response.status === 401) {
            window.location.href = '/public/login.html';
            return;
        }
        if (!response.ok) {
            throw new Error('Erro ao buscar status.');
        }
        const status = await readJsonResponse(response);
        atualizarStatus(status);
        atualizarAlertaPagamento(status);

        if (status.trial_until) {
            const trialDate = new Date(status.trial_until.replace(' ', 'T'));
            if (!Number.isNaN(trialDate.getTime()) && trialDate > new Date()) {
                trialBadge.classList.remove('is-hidden');
                trialBadge.textContent = `Trial ativo até ${trialDate.toLocaleDateString('pt-BR')}`;
            } else {
                trialBadge.classList.add('is-hidden');
            }
        } else {
            trialBadge.classList.add('is-hidden');
        }
    } catch (error) {
        statusMessage.textContent = 'Não foi possível carregar o status da assinatura.';
    }
};

const carregarConfiguracaoAssinatura = async () => {
    if (!planDescription) return;
    try {
        const response = await fetch('../api/subscription_config.php', { credentials: 'include' });
        if (!response.ok) {
            throw new Error('Erro ao buscar configuração.');
        }
        const config = await readJsonResponse(response);
        const price = Number(config.price || 0).toFixed(2).replace('.', ',');
        const trialDays = Number(config.trial_days || 0);
        altPaymentsEnabled = Boolean(config.enable_alt_payments);
        altPaymentsDefault = config.alt_payments_default || 'all';

        if (trialDays > 0) {
            planDescription.textContent = `Você tem ${trialDays} dias grátis. Depois R$ ${price}/mês. Cancele quando quiser.`;
        } else {
            planDescription.textContent = `Plano mensal R$ ${price}/mês. Cancele quando quiser.`;
        }

        if (btnAltPayment) {
            if (altPaymentsEnabled) {
                btnAltPayment.classList.remove('is-hidden');
            } else {
                btnAltPayment.classList.add('is-hidden');
            }
        }
    } catch (error) {
        planDescription.textContent = 'Plano mensal R$ 21,90/mês. Cancele quando quiser.';
        if (btnAltPayment) {
            btnAltPayment.classList.remove('is-hidden');
        }
    }
};

document.addEventListener('DOMContentLoaded', async () => {
    await carregarConfiguracaoAssinatura();
    await carregarStatus();
    carregarFaturas();
    if (btnAltPayment) {
        btnAltPayment.addEventListener('click', () => openAltPayment(altPaymentsDefault));
    }
    if (btnAlertPayment) {
        btnAlertPayment.addEventListener('click', () => openAltPayment(altPaymentsDefault));
    }
});
