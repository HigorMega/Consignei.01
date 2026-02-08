const statusMessage = document.getElementById('statusMessage');
const trialBadge = document.getElementById('trialBadge');
const btnAssinar = document.getElementById('btnAssinar');
const faturasContent = document.getElementById('faturasContent');
const planDescription = document.getElementById('planDescription');

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
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleDateString('pt-BR');
};

const formatDateTime = (value) => {
    if (!value) return '--';
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
    btnAssinar.textContent = 'Ativar assinatura';
    btnAssinar.onclick = async () => {
        btnAssinar.disabled = true;
        btnAssinar.textContent = 'Processando...';
        try {
            const response = await fetch('../api/billing_create_checkout.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({}),
            });

            const data = await readJsonResponse(response);

            if (!response.ok) {
                alert(data.message || 'Não foi possível iniciar a assinatura.');
                return;
            }

            const checkoutUrl = data.checkout_url || data.init_point;
            if (data.success && checkoutUrl) {
                window.location.href = checkoutUrl;
                return;
            }

            alert(data.message || 'Não foi possível iniciar a assinatura.');
        } catch (error) {
            alert('Não foi possível iniciar a assinatura. Tente novamente.');
        } finally {
            btnAssinar.disabled = false;
            btnAssinar.textContent = 'Ativar assinatura';
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

        if (trialDays > 0) {
            planDescription.textContent = `Você tem ${trialDays} dias grátis. Depois R$ ${price}/mês. Cancele quando quiser.`;
        } else {
            planDescription.textContent = `Plano mensal R$ ${price}/mês. Cancele quando quiser.`;
        }
    } catch (error) {
        planDescription.textContent = 'Plano mensal R$ 21,90/mês. Cancele quando quiser.';
    }
};

document.addEventListener('DOMContentLoaded', () => {
    carregarConfiguracaoAssinatura();
    carregarStatus();
    carregarFaturas();
});
