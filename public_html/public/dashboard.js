/**
 * SISTEMA DE GESTÃO - DASHBOARD JS (v30 - CORREÇÃO DE UNDEFINED E REMOÇÃO DE CORES)
 * - Adicionado tratamento para ler tanto 'codigo_produto' quanto 'codigo' (compatibilidade entre APIs).
 * - Removido fundo verde das linhas.
 * - Corrigida exibição de valores zerados.
 */

// --- VARIÁVEIS GLOBAIS ---
let vendaPendenteId = null;
window.tempConfigIA = { id: null, margem: 100, modo: 'lote' }; 
let itemParaExcluir = { id: null, tipo: null, ids: null }; 
window.loteAtualId = null; 
window.loteAtualStatus = null; 

// --- CONFIGURAÇÃO VISUAL (TOAST) ---
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
    customClass: {
        popup: 'custom-toast-card',
        title: 'custom-toast-title'
    },
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer)
        toast.addEventListener('mouseleave', Swal.resumeTimer)
    }
});

const Alert = Swal.mixin({
    customClass: { 
        popup: 'swal2-popup', 
        confirmButton: 'btn btn-primary', 
        cancelButton: 'btn btn-secondary' 
    },
    buttonsStyling: false,
    confirmButtonColor: '#d4af37', 
    cancelButtonColor: '#2d3436'
});

setTimeout(() => {
    const loading = document.getElementById('loadingOverlay');
    if (loading && loading.style.display !== 'none') {
        loading.style.display = 'none';
    }
}, 2000);

// --- ENDPOINTS ---
const API = {
    sessao: '../api/get_sessao.php', produtos: '../api/produtos.php',
    adicionar: '../api/adicionar_produto.php', editar: '../api/editar_produto.php', excluir: '../api/excluir_produto.php',
    vendas: '../api/vendas.php', editar_venda: '../api/editar_venda.php', excluir_venda: '../api/excluir_venda.php',
    config: '../api/configuracoes.php', fornecedores: '../api/fornecedores.php', categorias: '../api/categorias.php', 
    logout: '../api/logout.php', excluir_conta: '../api/excluir_conta.php', ia_importar: '../api/ia_importar.php', devolucao: '../api/devolucao.php',
    lotes: '../api/lotes.php', lote_itens: '../api/lote_itens.php',
    aprovar_lote: '../api/aprovar_lote.php',
    relatorio_lote: '../api/relatorio_lote.php',
    baixar_devolucao: '../api/baixar_devolucao_lote.php'
};

const App = {
    data: { produtos: [], vendas: [], fornecedores: [], categorias: [], config: {}, lojaId: null },
    charts: { vendas: null },
    filtro: { ano: new Date().getFullYear(), mes: new Date().getMonth() + 1 }
};


// --- SLUG (VITRINE) ---
let originalSlug = "";

function slugify(text) {
    return (text || "")
        .toString()
        .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, "")
        .replace(/\s+/g, "-")
        .replace(/-+/g, "-")
        .replace(/^-+|-+$/g, "");
}

function isValidSlug(slug) {
    return /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug) && slug.length <= 120;
}

function vitrineUrlFromSlug(slug) {
    if (!slug) return "";
    return `${window.location.origin}/vitrine/${slug}`;
}

function updateLinkUI(slug) {
    const url = vitrineUrlFromSlug(slug);
    const inputLink = document.getElementById('linkVitrine');
    const btnAbrir = document.getElementById('btnAbrirLink');
    if (inputLink) inputLink.value = url || "";
    if (btnAbrir) btnAbrir.href = url || "#";
}

// --- FORMATAÇÃO SEGURA ---
function formatMoney(v) {
    let val = parseFloat(v);
    if (isNaN(val)) val = 0;
    return val.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatDecimal(v) {
    const val = Number(v);
    if (Number.isNaN(val)) return '0,00';
    return val.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function parseDecimalInput(value) {
    if (typeof value === 'number') return value;
    if (!value) return 0;
    const normalized = value
        .toString()
        .trim()
        .replace(/\./g, '')
        .replace(',', '.');
    const parsed = parseFloat(normalized);
    return Number.isNaN(parsed) ? 0 : parsed;
}

document.addEventListener('DOMContentLoaded', () => {
    garantirInputCamera();
    initApp();
    setupStaticListeners();
    setupGlobalActions();
    renderizarSeletorData();

    const btnMobile = document.getElementById('mobileMenuBtn');
    if(btnMobile) {
        const novoBtn = btnMobile.cloneNode(true);
        btnMobile.parentNode.replaceChild(novoBtn, btnMobile);
        novoBtn.addEventListener('click', () => {
            document.getElementById('sidebar').classList.add('show');
            document.getElementById('mobileOverlay').classList.add('open');
        });
    }
    const overlay = document.getElementById('mobileOverlay');
    if(overlay) overlay.addEventListener('click', fecharMenuMobile);

    document.addEventListener('click', function(e){
        if(e.target.closest('#btnAprovarLote')) window.aprovarLoteAtual();
    });
});

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-action="abrir-modal-remessa"]');
    if (trigger) {
        event.preventDefault();
        if (typeof window.abrirModalNovaRemessa === 'function') {
            window.abrirModalNovaRemessa();
        }
    }
});

function fecharMenuMobile() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('mobileOverlay');
    if(sidebar) sidebar.classList.remove('show');
    if(overlay) overlay.classList.remove('open');
}

function fecharTeclado() {
    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'SELECT')) {
        document.activeElement.blur();
    }
}

function garantirInputCamera() {
    if (!document.getElementById('cameraInput')) {
        const input = document.createElement('input');
        input.type = 'file'; input.id = 'cameraInput'; input.accept = 'image/*'; input.multiple = true; input.style.display = 'none'; 
        document.body.appendChild(input);
        input.addEventListener('change', function() { window.enviarImagemIA(this); });
    } else {
        const input = document.getElementById('cameraInput');
        input.onchange = function() { window.enviarImagemIA(this); };
    }
}

async function fetchSafe(url, nomeModulo) {
    try {
        const res = await fetch(url);
        const text = await res.text();
        try { return JSON.parse(text); } 
        catch (e) { console.warn(`Erro JSON ${nomeModulo}`, text.substring(0,50)); return []; }
    } catch (e) { console.error(`Erro Conexão ${nomeModulo}`, e); return []; }
}

async function initApp() {
    try {
        const resSessao = await fetch(API.sessao);
        let sessao;
        try { sessao = await resSessao.json(); } catch(e) { sessao = { logado: false }; }
        
        if (!sessao.logado) { window.location.href = 'login'; return; }
        App.data.lojaId = sessao.loja_id;
        
        await Promise.all([
            carregarConfiguracoes(), carregarCategorias(), carregarFornecedores(), carregarProdutos(), carregarVendas()
        ]);
        renderizarGraficoVendas(); 
        gerarLinkVitrine();
    } catch (error) { console.error("Erro Geral:", error); } 
    finally {
        const loading = document.getElementById('loadingOverlay');
        if (loading) { loading.style.opacity = '0'; setTimeout(() => { loading.style.display = 'none'; }, 300); }
    }
}

// --- EVENTOS GLOBAIS ---
function setupGlobalActions() {
    document.body.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        const id = btn.dataset.id;
        
        switch(action) {
            case 'editar-produto': window.editarProdutoPorId(id); break;
            case 'excluir-produto': window.solicitarExclusao(id, 'produto'); break;
            case 'vender': window.venderProduto(id); break;
            case 'editar-venda': window.prepararEdicaoVenda(id); break;
            case 'excluir-venda': window.solicitarExclusao(id, 'venda'); break;
            case 'add-subcategoria': window.abrirModal('subcategoria', null, null, id); break;
            case 'editar-categoria': window.abrirModal('editar_categoria', id, btn.dataset.nome, btn.dataset.parent); break;
            case 'excluir-categoria': window.solicitarExclusao(id, 'categoria'); break;
            case 'editar-fornecedor': window.abrirModal('editar_fornecedor', id, btn.dataset.nome, null, btn.dataset.contato); break;
            case 'excluir-fornecedor': window.solicitarExclusao(id, 'fornecedor'); break;
            case 'camera-rapida': window.abrirCameraRapida(id); break;
        }
    });
}

function setupStaticListeners() {
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', () => {
            const target = item.getAttribute('data-target');
            if(target && document.getElementById(target)) {
                document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                document.querySelectorAll('.section-view').forEach(s => s.classList.remove('active'));
                item.classList.add('active');
                document.getElementById(target).classList.add('active');
                if(target === 'gestao-remessas') carregarLotes();
                fecharMenuMobile(); fecharTeclado();
            }
        });
    });

    document.querySelectorAll('.theme-opt').forEach(opt => {
        opt.addEventListener('click', () => {
            document.querySelectorAll('.theme-opt').forEach(o => o.classList.remove('selected'));
            opt.classList.add('selected');
        });
    });

    const btnLogout = document.getElementById('btnLogout');
    if(btnLogout) btnLogout.addEventListener('click', async (e) => {
        e.preventDefault(); await fetch(API.logout); window.location.href = 'login';
    });

    const btnCad = document.getElementById('btnIrCadastro');
    if(btnCad) btnCad.addEventListener('click', () => {
        resetarFormularioProduto(); 
        if(document.getElementById('cadastro')) {
            document.querySelectorAll('.section-view').forEach(s => s.classList.remove('active'));
            document.getElementById('cadastro').classList.add('active');
            fecharMenuMobile();
        }
    });

    if(document.getElementById('btnAnoAnt')) document.getElementById('btnAnoAnt').addEventListener('click', () => mudarAno(-1));
    if(document.getElementById('btnAnoProx')) document.getElementById('btnAnoProx').addEventListener('click', () => mudarAno(1));

    if(document.getElementById('prodCusto')) document.getElementById('prodCusto').addEventListener('input', calcularPrecoFinal);
    if(document.getElementById('prodMarkup')) document.getElementById('prodMarkup').addEventListener('input', calcularPrecoFinal);
    if(document.getElementById('prodPreco')) document.getElementById('prodPreco').addEventListener('input', calcularMarkupReverso);

    if(document.getElementById('formProduto')) document.getElementById('formProduto').addEventListener('submit', salvarProduto);
    if(document.getElementById('btnCancelarEdicao')) document.getElementById('btnCancelarEdicao').addEventListener('click', resetarFormularioProduto);
    if(document.getElementById('formConfig')) document.getElementById('formConfig').addEventListener('submit', salvarConfiguracoes);
    if(document.getElementById('btnCopiarLink')) document.getElementById('btnCopiarLink').addEventListener('click', copiarLinkVitrine);

    // --- SLUG: gerar automaticamente e atualizar link ---
    const slugEl = document.getElementById('cfgSlug');
    const nomeEl = document.getElementById('cfgNomeLoja');
    const btnGerarSlug = document.getElementById('btnGerarSlug');
    const btnAbrirLink = document.getElementById('btnAbrirLink');

    if (btnGerarSlug) btnGerarSlug.addEventListener('click', () => {
        const base = nomeEl ? nomeEl.value : '';
        const slug = slugify(base);
        if (slugEl) slugEl.value = slug;
        App.data.lojaSlug = slug || null;
        gerarLinkVitrine();
        if (slug && !isValidSlug(slug)) {
            Toast.fire({ icon: 'warning', title: 'Slug gerado, mas precisa ajustar.' });
        }
    });

    if (slugEl) {
        slugEl.addEventListener('input', () => {
            const cleaned = slugify(slugEl.value);
            // não força a cada tecla se o usuário estiver digitando hífen; mas mantém limpo
            slugEl.value = cleaned;
            App.data.lojaSlug = cleaned || null;
            gerarLinkVitrine();
        });
        slugEl.addEventListener('blur', () => {
            const cleaned = slugify(slugEl.value);
            slugEl.value = cleaned;
            App.data.lojaSlug = cleaned || null;
            gerarLinkVitrine();
        });
    }

    if (btnAbrirLink) {
        btnAbrirLink.addEventListener('click', (e) => {
            const link = document.getElementById('linkVitrine')?.value;
            if (!link) { e.preventDefault(); Toast.fire({ icon: 'warning', title: 'Link ainda não gerado.' }); }
        });
    }

    
    if(document.getElementById('btnAddCat')) document.getElementById('btnAddCat').addEventListener('click', () => abrirModal('categoria'));
    if(document.getElementById('btnAddForn')) document.getElementById('btnAddForn').addEventListener('click', () => abrirModal('fornecedor'));
    if(document.getElementById('btnConfirmarExclusao')) document.getElementById('btnConfirmarExclusao').addEventListener('click', window.confirmarExclusaoReal);
    if(document.getElementById('btnConfirmarDevolucao')) document.getElementById('btnConfirmarDevolucao').addEventListener('click', window.confirmarDevolucao);
    if(document.getElementById('btnSalvarEdicaoVenda')) document.getElementById('btnSalvarEdicaoVenda').addEventListener('click', window.salvarEdicaoVenda);
    if(document.getElementById('btnRealizarVenda')) document.getElementById('btnRealizarVenda').addEventListener('click', window.confirmarVendaReal);

    const btnScanner = document.getElementById('btnScanner');
    if(btnScanner) btnScanner.onclick = function(e) { e.preventDefault(); window.iniciarEscaneamentoIA(); };
    const btnDevolucao = document.getElementById('btnDevolucao');
    if(btnDevolucao) btnDevolucao.onclick = function(e) { e.preventDefault(); window.abrirModalDevolucao(); };
    
    if(document.getElementById('btnModalSalvar')) {
        document.getElementById('btnModalSalvar').addEventListener('click', async () => {
            const val = document.getElementById('modalInput').value;
            const extra = document.getElementById('modalInputExtra') ? document.getElementById('modalInputExtra').value : '';
            const pid = document.getElementById('modalParentId').value;
            const eid = document.getElementById('modalEditId').value;
            if(!val) return Alert.fire('Atenção', 'Nome obrigatório.', 'warning');
            
            let url = window.modalAction.includes('categoria') ? API.categorias : API.fornecedores;
            let body = window.modalAction.includes('categoria') ? {id:eid, nome:val, parent_id:pid} : {id:eid, nome:val, contato:extra};
            let method = eid ? 'PUT' : 'POST';

            await fetch(url, { method, body:JSON.stringify(body) });
            if(window.modalAction.includes('categoria')) carregarCategorias(); else carregarFornecedores();
        closeModal('modalGenerico');
            fecharTeclado();
        });
    }

    if(document.getElementById('btnConfirmarNovaRemessa')) {
        document.getElementById('btnConfirmarNovaRemessa').addEventListener('click', function() {
            const fornecedor = document.getElementById('remessaFornecedor').value;
            const obs = document.getElementById('remessaObs').value;
            const btn = this;
            if (!fornecedor) { Alert.fire('Atenção', 'Selecione um fornecedor.', 'warning'); return; }
            btn.innerText = 'Criando...'; btn.disabled = true;

            fetch(API.lotes, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ fornecedor_id: fornecedor, observacao: obs })
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    closeModal('modalNovaRemessa');
                    document.getElementById('remessaObs').value = '';
                    carregarLotes();
                    Toast.fire({ icon: 'success', title: 'Lote Criado!' });
                } else { Alert.fire('Erro', res.error || 'Erro desconhecido', 'error'); }
            })
            .catch(e => Alert.fire('Erro', 'Conexão falhou.', 'error'))
            .finally(() => { btn.innerText = 'Criar Lote'; btn.disabled = false; });
        });
    }
}

// --- FUNÇÃO CORRIGIDA: ABRIR MODAL NOVO LOTE ---
window.abrirModalNovaRemessa = function() {
    const select = document.getElementById('remessaFornecedor');
    select.innerHTML = '<option>Carregando...</option>';
    fetch(API.fornecedores).then(r => r.json()).then(data => {
        select.innerHTML = '';
        if(data.length === 0) {
             select.innerHTML = '<option value="">Nenhum fornecedor cadastrado</option>';
        } else {
            data.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id; opt.innerText = f.nome;
                select.appendChild(opt);
            });
        }
        openModal('modalNovaRemessa');
    }).catch(e => {
        select.innerHTML = '<option>Erro ao carregar</option>';
        Alert.fire('Erro', 'Não foi possível carregar fornecedores.', 'error');
    });
};

// --- CHECKBOX GERAL (INVENTÁRIO) ---
window.toggleCheckAll = function(source) {
    const checkboxes = document.querySelectorAll('.check-item');
    checkboxes.forEach(cb => cb.checked = source.checked);
    atualizarBotaoExcluirMassa();
};

window.atualizarBotaoExcluirMassa = function() {
    const checked = document.querySelectorAll('.check-item:checked');
    const btn = document.getElementById('btnExcluirMassa');
    const count = document.getElementById('countSelecionados');
    if(btn && count) {
        if(checked.length > 0) {
            btn.style.display = 'inline-flex';
            count.innerText = checked.length;
        } else {
            btn.style.display = 'none';
        }
    }
};

// --- FUNÇÃO DE EXCLUSÃO UNIFICADA E CORRIGIDA ---
window.solicitarExclusao = function(id, tipo, idsArray = null) {
    itemParaExcluir = { id, tipo, ids: idsArray };
    
    const titulo = document.querySelector('#modalConfirmacao .modal-title');
    const desc = document.querySelector('#modalConfirmacao .modal-desc');

    if (tipo === 'lote') {
        titulo.innerText = 'Excluir Lote?';
        desc.innerText = 'Todos os itens e o histórico deste lote serão apagados permanentemente.';
    } else if (tipo === 'lote_massa') {
        titulo.innerText = 'Excluir Itens?';
        desc.innerText = `Você selecionou ${idsArray ? idsArray.length : 0} itens para remover do lote.`;
    } else if (tipo === 'produto_massa') {
        titulo.innerText = 'Excluir Produtos?';
        desc.innerText = `Você vai apagar ${idsArray ? idsArray.length : 0} produtos do estoque.`;
    } else {
        titulo.innerText = 'Excluir?';
        desc.innerText = 'Essa ação não pode ser desfeita.';
    }

    openModal('modalConfirmacao');
};

window.confirmarExclusaoReal = async function() {
    const { id, tipo, ids } = itemParaExcluir;
    const btn = document.getElementById('btnConfirmarExclusao');
    const textoOriginal = btn.innerText;
    btn.innerText = "Apagando...";
    btn.disabled = true;

    try {
        if (tipo === 'lote') {
            await fetch(API.lotes + '?id=' + id, { method: 'DELETE' });
            document.querySelector('[data-target="gestao-remessas"]').click();
            
        } else if (tipo === 'lote_massa') {
            await fetch(API.lote_itens, { method: 'DELETE', body: JSON.stringify({ ids: ids }) });
            carregarItensDoLote(window.loteAtualId);
            document.getElementById('checkAllLote').checked = false;
            document.getElementById('barLoteActions').style.display = 'none';

        } else if (tipo === 'produto_massa') {
            const promises = ids.map(pid => fetch(API.excluir + '?id=' + pid, { method: 'DELETE' }));
            await Promise.all(promises);
            carregarProdutos();
            document.getElementById('btnExcluirMassa').style.display = 'none';
            document.getElementById('checkAll').checked = false;

        } else if (tipo === 'categoria') {
            await fetch(API.categorias + '?id=' + id, { method: 'DELETE' });
            carregarCategorias();

        } else if (tipo === 'fornecedor') {
            await fetch(API.fornecedores + '?id=' + id, { method: 'DELETE' });
            carregarFornecedores();

        } else if (tipo === 'produto') {
            await fetch(API.excluir + '?id=' + id, { method: 'DELETE' });
            carregarProdutos();

        } else if (tipo === 'venda') {
            await fetch(API.excluir_venda + '?id=' + id, { method: 'DELETE' });
            initApp();
        }

        closeModal('modalConfirmacao');
        Toast.fire({ icon: 'success', title: 'Removido com sucesso!' });

    } catch(e) {
        console.error(e);
        Alert.fire('Erro', 'Falha ao excluir.', 'error');
    } finally {
        btn.innerText = textoOriginal;
        btn.disabled = false;
    }
};

window.excluirSelecionados = function() {
    const checked = document.querySelectorAll('.check-item:checked');
    if(checked.length === 0) return;
    const ids = Array.from(checked).map(cb => cb.value);
    window.solicitarExclusao(null, 'produto_massa', ids);
};

// --- CARREGAMENTO ---
async function carregarCategorias() {
    App.data.categorias = await fetchSafe(API.categorias, "Categorias");
    renderizarTabelaCategorias(); popularSelects();
}

function renderizarTabelaCategorias() {
    const tbody = document.getElementById('listaCategorias');
    if(!tbody) return;
    tbody.innerHTML = '';
    const cats = Array.isArray(App.data.categorias) ? App.data.categorias : [];
    const pais = cats.filter(c => !c.parent_id || c.parent_id == 0);
    
    if (pais.length === 0) {
        tbody.innerHTML = '<tr><td colspan="2" class="center" style="padding:40px; color:#999;">Nenhuma categoria.</td></tr>';
        return;
    }

    pais.forEach(pai => {
        tbody.innerHTML += `
            <tr>
                <td><strong>${pai.nome}</strong></td>
                <td class="col-actions">
                    <div class="actions-wrapper">
                        <button class="btn-icon add-sub" data-action="add-subcategoria" data-id="${pai.id}"><i class="ph ph-plus"></i></button>
                        <button class="btn-icon edit" data-action="editar-categoria" data-id="${pai.id}" data-nome="${pai.nome}"><i class="ph ph-pencil"></i></button>
                        <button class="btn-icon del" data-action="excluir-categoria" data-id="${pai.id}"><i class="ph ph-trash"></i></button>
                    </div>
                </td>
            </tr>`;
        cats.filter(f => f.parent_id == pai.id).forEach(filho => {
            tbody.innerHTML += `
                <tr>
                    <td style="padding-left: 30px; color: #666;">↳ ${filho.nome}</td>
                    <td class="col-actions">
                        <div class="actions-wrapper">
                            <button class="btn-icon edit" data-action="editar-categoria" data-id="${filho.id}" data-nome="${filho.nome}" data-parent="${pai.id}"><i class="ph ph-pencil"></i></button>
                            <button class="btn-icon del" data-action="excluir-categoria" data-id="${filho.id}"><i class="ph ph-trash"></i></button>
                        </div>
                    </td>
                </tr>`;
        });
    });
}

async function carregarFornecedores() {
    App.data.fornecedores = await fetchSafe(API.fornecedores, "Fornecedores");
    const el = document.getElementById('listaFornecedores');
    if(el) {
        if(App.data.fornecedores.length === 0) el.innerHTML = '<tr><td colspan="3" class="center" style="padding:40px; color:#999;">Nenhum fornecedor.</td></tr>';
        else el.innerHTML = App.data.fornecedores.map(f => `
            <tr>
                <td>${f.nome}</td>
                <td>${f.contato||'-'}</td>
                <td class="col-actions">
                    <div class="actions-wrapper">
                        <button class="btn-icon edit" data-action="editar-fornecedor" data-id="${f.id}" data-nome="${f.nome}" data-contato="${f.contato||''}"><i class="ph ph-pencil"></i></button>
                        <button class="btn-icon del" data-action="excluir-fornecedor" data-id="${f.id}"><i class="ph ph-trash"></i></button>
                    </div>
                </td>
            </tr>`).join('');
    }
    popularSelects();
}

function popularSelects() {
    const cats = Array.isArray(App.data.categorias) ? App.data.categorias : [];
    const forns = Array.isArray(App.data.fornecedores) ? App.data.fornecedores : [];
    let htmlCat = '<option value="">Selecione...</option>';
    cats.filter(c => !c.parent_id).forEach(p => {
        htmlCat += `<option value="${p.nome}" style="font-weight:bold">${p.nome}</option>`;
        cats.filter(f => f.parent_id == p.id).forEach(f => htmlCat += `<option value="${f.nome}">&nbsp;&nbsp;↳ ${f.nome}</option>`);
    });
    if(document.getElementById('prodCategoria')) document.getElementById('prodCategoria').innerHTML = htmlCat;
    let htmlForn = '<option value="">Selecione...</option>' + forns.map(f => `<option value="${f.id}">${f.nome}</option>`).join('');
    if(document.getElementById('prodFornecedor')) document.getElementById('prodFornecedor').innerHTML = htmlForn;
}

// --- TABELA DE PRODUTOS (AJUSTADA PARA O VISUAL NOVO) ---
async function carregarProdutos() {
    App.data.produtos = await fetchSafe(API.produtos, "Produtos");
    const lista = document.getElementById('listaProdutos');
    if(!lista) return;
    const ativos = App.data.produtos.filter(p => p.status === 'ativo');
    if(ativos.length === 0) { lista.innerHTML = '<tr><td colspan="7" class="center" style="padding: 40px; color:#999;">Nenhum produto em estoque.</td></tr>'; return; }
    
    lista.innerHTML = ativos.map(p => {
        const imgUrl = p.imagem ? (p.imagem.startsWith('http') ? p.imagem : `uploads/${p.imagem}`) : null;
        const fotoHTML = imgUrl ? 
            `<img src="${imgUrl}" style="width:40px;height:40px;object-fit:cover;border-radius:6px; border:1px solid #ddd;">` : 
            `<span class="badge-pendente" data-action="camera-rapida" data-id="${p.id}"><i class="ph ph-camera"></i></span>`;
            
        return `
            <tr>
                <td class="col-checkbox"><input type="checkbox" class="check-item" value="${p.id}" onchange="window.atualizarBotaoExcluirMassa()"></td>
                <td class="col-thumb">${fotoHTML}</td>
                <td class="col-code"><span style="font-family:'Courier New', monospace; font-weight:700; background:#f5f5f5; padding:2px 6px; border-radius:4px; font-size:12px; color:#333;">${p.codigo_produto || '-'}</span></td>
                <td><div style="font-weight:600; font-size:13px; color:#333; line-height:1.2;">${p.nome}</div></td>
                <td class="col-price">${formatMoney(p.preco)}</td>
                <td class="col-qty">${p.quantidade}</td>
                <td class="col-actions">
                    <div class="actions-wrapper">
                        <button class="btn-icon sell" title="Vender" data-action="vender" data-id="${p.id}"><i class="ph-fill ph-currency-dollar"></i></button>
                        <button class="btn-icon edit" title="Editar" data-action="editar-produto" data-id="${p.id}"><i class="ph ph-pencil"></i></button>
                        <button class="btn-icon del" title="Excluir" data-action="excluir-produto" data-id="${p.id}"><i class="ph ph-trash"></i></button>
                    </div>
                </td>
            </tr>`;
    }).join('');
    
    document.getElementById('buscaProduto').addEventListener('keyup', function() {
        const termo = this.value.toLowerCase();
        const linhas = lista.getElementsByTagName('tr');
        for (let tr of linhas) {
            const texto = tr.innerText.toLowerCase();
            tr.style.display = texto.includes(termo) ? '' : 'none';
        }
    });
}

// --- SCANNER COM MARGEM ---
async function comprimirImagem(file) {
    return new Promise((resolve) => {
        const maxWidth = 1000; const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = (event) => {
            const img = new Image(); img.src = event.target.result;
            img.onload = () => {
                let w = img.width, h = img.height;
                if (w > maxWidth) { h *= maxWidth / w; w = maxWidth; }
                const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                resolve(canvas.toDataURL('image/jpeg', 0.85).split(',')[1]);
            };
        };
    });
}

window.iniciarEscaneamentoIA = async function() {
    if (!window.loteAtualId) return Alert.fire('Atenção', 'Abra um lote primeiro.', 'warning');
    
    if (window.loteAtualStatus === 'ativo' || window.loteAtualStatus === 'finalizado') {
        return Alert.fire('Atenção', 'Este lote já está aprovado ou finalizado. Não é possível adicionar itens.', 'warning');
    }

    const { value: margem } = await Swal.fire({
        title: 'Definir Margem de Lucro',
        text: 'Qual porcentagem deseja aplicar sobre o custo?',
        input: 'number',
        inputValue: 100,
        inputLabel: 'Margem (%)',
        showCancelButton: true,
        confirmButtonText: 'Continuar para Câmera',
        confirmButtonColor: '#d4af37' 
    });

    if (margem !== undefined) { 
        window.tempConfigIA = { id: null, margem: margem, modo: 'lote' };
        document.getElementById('cameraInput').click();
    }
};

window.enviarImagemIA = async function(input) {
    if (!input.files || input.files.length === 0) return;
    const total = input.files.length;
    let importados = 0; 
    
    const textoLoading = 'Analisando Lista...';
    Swal.fire({ title: 'Processando...', text: `${textoLoading}`, allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    for (let i = 0; i < total; i++) {
        const base64 = await comprimirImagem(input.files[i]);
        const fd = new FormData(); 
        fd.append('imagem', base64);
        fd.append('lote_id', window.loteAtualId);
        fd.append('margem', window.tempConfigIA.margem);

        try {
            const res = await fetch(API.lote_itens, { method: 'POST', body: fd });
            const result = JSON.parse(await res.text());
            if (result.success) importados += parseInt(result.qtd); 
        } catch (e) { console.error(e); }
    }
    
    input.value = ''; 
    Toast.fire({ icon: 'success', title: `${importados} itens processados!` });
    await carregarCategorias();
    carregarItensDoLote(window.loteAtualId); 
};

// --- DEMAIS FUNÇÕES ---
async function salvarProduto(e) {
    e.preventDefault(); fecharTeclado();
    const fd = new FormData(e.target);
    fd.append('preco', document.getElementById('prodPreco').value);
    const url = fd.get('id') ? API.editar : API.adicionar;
    try {
        const res = await fetch(url, { method: 'POST', body: fd });
        const json = await res.json();
        if(json.success) { 
            Toast.fire({ icon: 'success', title: 'Produto salvo!' });
            resetarFormularioProduto(); carregarProdutos(); 
        } else Alert.fire('Erro', 'Não foi possível salvar.', 'error');
    } catch(err) { Alert.fire('Erro', 'Erro de conexão.', 'error'); }
}

window.editarProdutoPorId = function(id) {
    const p = App.data.produtos.find(x => x.id == id);
    if(!p) return;
    document.getElementById('prodId').value = p.id;
    document.getElementById('prodCodigo').value = p.codigo_produto;
    document.getElementById('prodNome').value = p.nome;
    if(document.getElementById('prodCategoria')) document.getElementById('prodCategoria').value = p.categoria;
    if(document.getElementById('prodFornecedor')) document.getElementById('prodFornecedor').value = p.fornecedor_id;
    document.getElementById('prodQtd').value = p.quantidade;
    document.getElementById('prodCusto').value = p.preco_custo;
    document.getElementById('prodMarkup').value = p.markup;
    document.getElementById('prodPreco').value = p.preco;
    document.getElementById('formTitle').innerText = 'Editar Peça';
    document.getElementById('btnCancelarEdicao').style.display = 'block';
    document.querySelectorAll('.section-view').forEach(s => s.classList.remove('active'));
    document.getElementById('cadastro').classList.add('active');
    fecharMenuMobile();
};

function resetarFormularioProduto() {
    document.getElementById('formProduto').reset();
    document.getElementById('prodId').value = '';
    document.getElementById('formTitle').innerText = 'Cadastrar Nova Peça';
    document.getElementById('btnCancelarEdicao').style.display = 'none';
    document.querySelectorAll('.section-view').forEach(s => s.classList.remove('active'));
    document.getElementById('produtos').classList.add('active');
    fecharTeclado();
}

window.venderProduto = function(id) {
    vendaPendenteId = id; 
    openModal('modalConfirmarVenda'); 
};

window.confirmarVendaReal = async function() {
    if(!vendaPendenteId) return;
    const btn = document.getElementById('btnRealizarVenda');
    btn.innerText = "Processando..."; btn.disabled = true;
    try {
        await fetch(API.vendas, { method:'POST', body:JSON.stringify({produto_id: vendaPendenteId}) });
        closeModal('modalConfirmarVenda');
        Toast.fire({ icon: 'success', title: 'Venda registrada!' });
        initApp(); 
    } catch(e) { Alert.fire('Erro', 'Falha ao registrar venda.', 'error'); } 
    finally { btn.innerText = "Confirmar Venda"; btn.disabled = false; vendaPendenteId = null; }
};

window.prepararEdicaoVenda = function(id) {
    const v = App.data.vendas.find(x => x.id == id); if (!v) return;
    document.getElementById('editVendaId').value = v.id;
    document.getElementById('editVendaData').value = new Date(v.data_venda).toISOString().split('T')[0];
    document.getElementById('editVendaPreco').value = v.preco_venda;
    openModal('modalEditarVenda');
};

window.salvarEdicaoVenda = async function() {
    const id = document.getElementById('editVendaId').value;
    const novaData = document.getElementById('editVendaData').value;
    const novoPreco = document.getElementById('editVendaPreco').value;
    if(!id || !novaData || !novoPreco) return Alert.fire('Atenção', 'Preencha tudo.', 'warning');
    try {
        const res = await fetch(API.editar_venda, { method: 'POST', body: JSON.stringify({ id, data_venda: novaData, preco_venda: novoPreco }) });
        if((await res.json()).success) {
            closeModal('modalEditarVenda');
            Toast.fire({ icon: 'success', title: 'Atualizado!' });
            initApp();
        } else Alert.fire('Erro', 'Erro ao editar.', 'error');
    } catch(e) { Alert.fire('Erro', 'Falha na conexão.', 'error'); }
};

window.abrirModal = function(tipo, id=null, nome=null, parent=null, extra=null) {
    fecharTeclado();
    document.getElementById('modalInput').value = nome||'';
    if(document.getElementById('modalInputExtra')) {
        document.getElementById('modalInputExtra').value = extra||'';
        document.getElementById('modalInputExtra').style.display = tipo.includes('fornecedor') ? 'block' : 'none';
    }
    document.getElementById('modalParentId').value = parent||'';
    document.getElementById('modalEditId').value = id||'';
    const titles = { categoria:'Nova Categoria', subcategoria:'Nova Subcategoria', editar_categoria:'Editar Categoria', fornecedor:'Novo Fornecedor', editar_fornecedor: 'Editar Fornecedor' };
    document.getElementById('modalGenericoTitle').innerText = titles[tipo] || 'Item';
    openModal('modalGenerico');
    window.modalAction = tipo;
    setTimeout(() => document.getElementById('modalInput').focus(), 100);
};

async function carregarVendas() {
    App.data.vendas = await fetchSafe(API.vendas, "Vendas");
    const el = document.getElementById('listaVendas');
    if(el) el.innerHTML = App.data.vendas.slice(0,30).map(v => `
        <tr>
            <td>${new Date(v.data_venda).toLocaleDateString()}</td>
            <td><div style="font-size:12px; line-height:1.2; font-weight:600; color:#333;">${v.nome_produto}</div></td>
            <td class="col-price">${formatMoney(v.preco_venda)}</td>
            <td class="col-price" style="color:var(--success);">+${formatMoney(v.lucro)}</td>
            <td class="col-actions">
                <div class="actions-wrapper">
                    <button class="btn-icon edit" data-action="editar-venda" data-id="${v.id}"><i class="ph ph-pencil"></i></button>
                    <button class="btn-icon del" data-action="excluir-venda" data-id="${v.id}"><i class="ph ph-trash"></i></button>
                </div>
            </td>
        </tr>`).join('');
}

async function carregarConfiguracoes() {
    try {
        const dados = await fetchSafe(API.config, "Config");
        App.data.config = dados || {};

        if(document.getElementById('cfgNomeLoja') && dados.nome_loja) document.getElementById('cfgNomeLoja').value = dados.nome_loja;
        if(document.getElementById('cfgWhats') && dados.whatsapp) document.getElementById('cfgWhats').value = dados.whatsapp;
        if(document.getElementById('cfgVendedor')) document.getElementById('cfgVendedor').value = dados.nome_vendedor || dados.vendedor || '';
        if(document.getElementById('cfgInstagram') && dados.instagram) document.getElementById('cfgInstagram').value = dados.instagram;
        if(document.getElementById('cfgEstiloFonte')) document.getElementById('cfgEstiloFonte').value = dados.estilo_fonte || 'classico';
        if(document.getElementById('cfgCorFundo')) document.getElementById('cfgCorFundo').value = dados.cor_fundo || '#ffffff';
        if(document.getElementById('cfgTexturaFundo')) document.getElementById('cfgTexturaFundo').value = dados.textura_fundo || 'liso';
        if(document.getElementById('cfgBannerAviso')) document.getElementById('cfgBannerAviso').value = dados.banner_aviso || '';

        // --- SLUG ---
        const slugInput = document.getElementById('cfgSlug');
        const slugAtual = (dados.slug || dados.loja_slug || App.data.lojaSlug || '').toString();
        if (slugInput) slugInput.value = slugAtual;
        originalSlug = slugAtual;
        App.data.lojaSlug = slugAtual || null;

        // Atualiza link da vitrine
        gerarLinkVitrine();

        if(dados.tema) {
            document.querySelectorAll('.theme-opt').forEach(t => t.classList.remove('selected'));
            const temaBox = document.querySelector(`.theme-opt[data-theme="${dados.tema}"]`);
            if(temaBox) temaBox.classList.add('selected');
        }
    } catch(e){}
}

async function salvarConfiguracoes(e) {
    e.preventDefault(); fecharTeclado();

    const tema = document.querySelector('.theme-opt.selected')?.getAttribute('data-theme') || 'rose';
    const nomeLoja = document.getElementById('cfgNomeLoja')?.value || '';
    const whatsapp = document.getElementById('cfgWhats')?.value || '';
    const vendedor = document.getElementById('cfgVendedor')?.value || '';
    const instagram = document.getElementById('cfgInstagram')?.value || '';
    const estiloFonte = document.getElementById('cfgEstiloFonte')?.value || 'classico';
    const corFundo = document.getElementById('cfgCorFundo')?.value || '#ffffff';
    const texturaFundo = document.getElementById('cfgTexturaFundo')?.value || 'liso';
    const bannerAviso = document.getElementById('cfgBannerAviso')?.value || '';

    // Slug (opcional, mas recomendado)
    const slugEl = document.getElementById('cfgSlug');
    let slug = slugEl ? slugEl.value : '';
    slug = slugify(slug);

    if (slugEl) slugEl.value = slug;

    if (slug && !isValidSlug(slug)) {
        return Alert.fire('Atenção', 'Slug inválido. Use apenas letras minúsculas, números e hífen (sem espaços).', 'warning');
    }

    const payload = { 
        nome_loja: nomeLoja,
        whatsapp: whatsapp,
        vendedor: vendedor,
        instagram: instagram,
        tema: tema,
        estilo_fonte: estiloFonte,
        cor_fundo: corFundo,
        textura_fundo: texturaFundo,
        banner_aviso: bannerAviso
    };

    // Envia slug somente se existir campo na tela (configurações.html ou aba Config) e se mudou
    if (slugEl) {
        payload.slug = slug || null;
    }

    try {
        const res = await fetch(API.config, { 
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const json = await res.json();

        if (json.success) {
            Toast.fire({ icon: 'success', title: 'Salvo!' });

            // Atualiza slug local (para link da vitrine)
            if (slugEl) {
                App.data.lojaSlug = (json.slug || slug || '').toString() || null;
                originalSlug = App.data.lojaSlug || '';
                gerarLinkVitrine();
            }
        } else {
            Alert.fire('Erro', json.error || 'Não foi possível salvar.', 'error');
        }
    } catch (e) {
        Alert.fire('Erro', 'Erro de conexão.', 'error');
    }
}

function calcularPrecoFinal() {
    const c = parseFloat(document.getElementById('prodCusto').value)||0;
    const m = parseFloat(document.getElementById('prodMarkup').value)||0;
    document.getElementById('prodPreco').value = (c * (1 + (m/100))).toFixed(2);
}
function calcularMarkupReverso() {
    const c = parseFloat(document.getElementById('prodCusto').value)||0;
    const p = parseFloat(document.getElementById('prodPreco').value)||0;
    if (c > 0) document.getElementById('prodMarkup').value = (((p - c) / c) * 100).toFixed(2);
}

function gerarLinkVitrine() {
    if(!App.data.lojaId) return;

    // Prioriza slug (URL bonita), senão usa fallback com querystring
    const slug = App.data.lojaSlug || document.getElementById('cfgSlug')?.value || '';
    if (slug) {
        updateLinkUI(slug);
        return;
    }

    const el = document.getElementById('linkVitrine');
    const btnAbrir = document.getElementById('btnAbrirLink');

    const baseUrl = window.location.origin;
    const fallback = `${baseUrl}/vitrine.html?loja=${App.data.lojaId}`;

    if(el) el.value = fallback;
    if(btnAbrir) btnAbrir.href = fallback;
}
function copiarLinkVitrine() {
    const el = document.getElementById('linkVitrine');
    const btnAbrir = document.getElementById('btnAbrirLink');
    if(el) {
        el.select();
        document.execCommand('copy');
        if (btnAbrir) btnAbrir.href = el.value || "#";
        Toast.fire({ icon: 'success', title: 'Copiado!' });
    }
}

function renderizarSeletorData() {
    if(document.getElementById('labelAno')) document.getElementById('labelAno').innerText = App.filtro.ano;
    const m = ['JAN','FEV','MAR','ABR','MAI','JUN','JUL','AGO','SET','OUT','NOV','DEZ'];
    if(document.getElementById('listaMeses')) document.getElementById('listaMeses').innerHTML = m.map((m,i) => `<div class="month-card ${i+1===App.filtro.mes?'active':''}" onclick="window.mudarMes(${i+1})">${m}</div>`).join('');
}
window.mudarAno = function(d) { App.filtro.ano+=d; renderizarSeletorData(); renderizarGraficoVendas(); };
window.mudarMes = function(m) { App.filtro.mes=m; renderizarSeletorData(); renderizarGraficoVendas(); };

function renderizarGraficoVendas() {
    const canvas = document.getElementById('chartVendas'); if(!canvas) return;
    const ctx = canvas.getContext('2d'); if(App.charts.vendas) App.charts.vendas.destroy();
    const vendas = App.data.vendas || [];
    const { ano, mes } = App.filtro;
    const diasNoMes = new Date(ano, mes, 0).getDate();
    const data = new Array(diasNoMes).fill(0);
    let total=0, lucro=0, count=0;
    vendas.forEach(v => {
        const d = new Date(v.data_venda);
        if(d.getFullYear()===ano && (d.getMonth()+1)===mes) {
            const val = parseFloat(v.preco_venda); data[d.getDate()-1] += val; total+=val; count++; lucro+=parseFloat(v.lucro);
        }
    });
    if(document.getElementById('kpiVendasCount')) document.getElementById('kpiVendasCount').innerText = count;
    if(document.getElementById('kpiVendasTotal')) document.getElementById('kpiVendasTotal').innerText = formatMoney(total);
    if(document.getElementById('kpiVendasLucro')) document.getElementById('kpiVendasLucro').innerText = formatMoney(lucro);
    if(document.getElementById('kpiVendasCusto')) document.getElementById('kpiVendasCusto').innerText = formatMoney(total - lucro);
    App.charts.vendas = new Chart(ctx, {
        type: 'line',
        data: { labels: Array.from({length:diasNoMes},(_,i)=>i+1), datasets: [{ label:'Vendas', data:data, borderColor:'#d4af37', backgroundColor:'rgba(212,175,55,0.1)', borderWidth: 2, fill:true, tension: 0.4 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins:{legend:{display:false}}, scales:{x:{grid:{display:false}}, y:{beginAtZero:true, grid:{color:'#f0f0f0'}}} }
    });
}

window.abrirModalDevolucao = function() {
    fecharTeclado();
    const ativos = App.data.produtos.filter(p => p.status === 'ativo' && p.quantidade > 0);
    const select = document.getElementById('selectDevolucao');
    if(select) select.innerHTML = ativos.length ? ativos.map(p => `<option value="${p.id}">${p.nome} (Qtd: ${p.quantidade})</option>`).join('') : '<option>Estoque vazio</option>';
    openModal('modalDevolucao');
};

window.confirmarDevolucao = async function() {
    fecharTeclado();
    const id = document.getElementById('selectDevolucao').value;
    const qtd = document.getElementById('qtdDevolucao').value;
    if (!qtd || qtd <= 0) return Alert.fire('Atenção', 'Informe a quantidade.', 'warning');
    try {
        const res = await fetch(API.devolucao, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ produto_id: id, quantidade: qtd }) });
        if ((await res.json()).success) {
            Toast.fire({ icon: 'success', title: 'Devolvido!' });
            closeModal('modalDevolucao');
            carregarProdutos();
        } else Alert.fire('Erro', 'Erro ao devolver.', 'error');
    } catch (e) { Alert.fire('Erro', 'Falha ao devolver.', 'error'); }
};

window.abrirCameraRapida = function(id) { editarProdutoPorId(id); };

// ==========================================
//  GESTÃO DE REMESSAS (LOTES)
// ==========================================

// FUNÇÃO NOVA: ADICIONAR MANUALMENTE NO LOTE (MODAL PREMIUM INJETADO)
window.adicionarItemManualLote = async function() {
    if (!window.loteAtualId) return;
    
    // CORREÇÃO:
    if (window.loteAtualStatus === 'ativo' || window.loteAtualStatus === 'finalizado') {
        return Alert.fire('Atenção', 'Este lote já está aprovado ou finalizado.', 'warning');
    }

    const { value: formValues } = await Swal.fire({
        title: 'Adicionar Produto',
        html: `
            <div style="display:grid; grid-template-columns: 1fr; gap:10px; text-align:left;">
                <div>
                    <label style="font-size:12px; font-weight:600; color:#666;">Nome</label>
                    <input id="swal-nome" class="swal2-input" placeholder="Ex: Anel Ouro 18k" style="margin:5px 0 0 0 !important; width:100% !important;">
                </div>
                <div>
                    <label style="font-size:12px; font-weight:600; color:#666;">Código (Opcional)</label>
                    <input id="swal-codigo" class="swal2-input" placeholder="Ex: AN123" style="margin:5px 0 0 0 !important; width:100% !important;">
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div>
                        <label style="font-size:12px; font-weight:600; color:#666;">Custo (R$)</label>
                        <input id="swal-custo" type="number" step="0.01" class="swal2-input" placeholder="0,00" style="margin:5px 0 0 0 !important; width:100% !important;">
                    </div>
                    <div>
                        <label style="font-size:12px; font-weight:600; color:#666;">Venda (R$)</label>
                        <input id="swal-venda" type="number" step="0.01" class="swal2-input" placeholder="0,00" style="margin:5px 0 0 0 !important; width:100% !important;">
                    </div>
                </div>
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Salvar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#d4af37',
        preConfirm: () => {
            return {
                nome: document.getElementById('swal-nome').value,
                codigo: document.getElementById('swal-codigo').value,
                custo: document.getElementById('swal-custo').value,
                venda: document.getElementById('swal-venda').value
            }
        }
    });

    if (formValues) {
        if (!formValues.nome || !formValues.custo) {
            return Toast.fire({ icon: 'warning', title: 'Nome e Custo são obrigatórios' });
        }

        const fd = new FormData();
        fd.append('lote_id', window.loteAtualId);
        fd.append('nome', formValues.nome);
        fd.append('codigo', formValues.codigo);
        fd.append('custo', formValues.custo);
        fd.append('venda', formValues.venda);

        try {
            const res = await fetch(API.lote_itens, { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                Toast.fire({ icon: 'success', title: 'Item adicionado!' });
                carregarItensDoLote(window.loteAtualId);
            } else {
                Alert.fire('Erro', json.error || 'Falha ao adicionar', 'error');
            }
        } catch (e) {
            Alert.fire('Erro', 'Erro de conexão', 'error');
        }
    }
};

function carregarLotes() {
    const grid = document.getElementById('listaRemessas');
    if(!grid) return;
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#999;"><div class="spinner" style="margin:0 auto 15px;"></div>Carregando remessas...</div>';

    fetch(API.lotes)
        .then(r => r.json())
        .then(lotes => {
            grid.innerHTML = '';
            if (lotes.length === 0) {
                grid.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 50px; color: #999; border: 2px dashed #eee; border-radius: 20px;"><p>Nenhuma remessa encontrada.</p><button class="btn btn-primary" data-action="abrir-modal-remessa" style="margin-top:10px;">Criar Primeira</button></div>`;
                return;
            }
            lotes.forEach(lote => {
                let statusClass = lote.status === 'ativo' ? 'status-ativo' : (lote.status === 'finalizado' ? 'status-finalizado' : 'status-rascunho');
                let icon = lote.status === 'ativo' ? '<i class="ph-fill ph-check"></i>' : (lote.status === 'finalizado' ? '<i class="ph-fill ph-check-circle"></i>' : '<i class="ph-fill ph-timer"></i>');
                const data = new Date(lote.data_entrada).toLocaleDateString('pt-BR');
                const card = document.createElement('div');
                card.className = 'remessa-card';
                card.onclick = () => window.abrirLote(lote.id, lote.status);
                
                // Botão de Conferência/Devolução REMOVIDO PARA LIMPEZA
                let btnConferir = '';

                if (lote.status === 'finalizado') btnConferir = `<small style="display:block; text-align:center; margin-top:10px; color:#666;">Finalizado</small>`;

                // DEFINE LABEL DE STATUS PARA O CARD
                let labelStatus = '';
                if(lote.status === 'ativo') labelStatus = '<span class="badge-status-card bg-aprovado">Aprovado</span>';
                else if(lote.status === 'finalizado') labelStatus = '<span class="badge-status-card bg-finalizado">Finalizado</span>';
                else labelStatus = '<span class="badge-status-card bg-pendente">Pendente</span>';

                card.innerHTML = `
                    <div class="remessa-status ${statusClass}">${icon}</div>
                    <div class="remessa-info">
                        ${labelStatus}
                        <p>Lote #${lote.id} • ${data}</p>
                        <h4>${lote.nome_empresa || 'Fornecedor'}</h4>
                        <p>${lote.observacao || ''}</p>
                        ${btnConferir}
                    </div>
                `;
                grid.appendChild(card);
            });
        })
        .catch(e => { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:red;">Erro ao carregar lotes.</div>'; });
}

window.abrirLote = function(id, status) {
    window.loteAtualId = id;
    window.loteAtualStatus = status;

    document.querySelectorAll('.section-view').forEach(s => s.classList.remove('active'));
    document.getElementById('view-lote').classList.add('active');
    document.getElementById('loteTitulo').innerText = `Lote #${id}`;
    
    // Atualiza Badge de Status
    const badge = document.getElementById('loteStatusBadge');
    if(status === 'ativo') {
        badge.innerText = 'APROVADO'; 
        badge.style.background = '#e6fffa'; badge.style.color = 'var(--success)';
    } else if (status === 'finalizado') {
        badge.innerText = 'FINALIZADO';
        badge.style.background = '#f1f2f6'; badge.style.color = '#636e72';
    } else { 
        badge.innerText = 'PENDENTE'; 
        badge.style.background = '#fff9e6'; badge.style.color = '#d4af37';
    }

    // Controla Visibilidade dos Botões de Edição
    const toolbar = document.getElementById('loteToolbar');
    // Se Finalizado: Mostra Botão Excluir e Relatório
    if(status === 'finalizado') {
        toolbar.innerHTML = `
            <button class="btn-tool delete" onclick="window.excluirLoteAtual()" title="Excluir Histórico"><i class="ph-bold ph-trash"></i> Excluir</button>
            <button class="btn-tool" onclick="window.abrirConferenciaLote(${id})" style="background:#636e72;"><i class="ph-bold ph-file-text"></i> Relatório</button>
        `;
        toolbar.style.display = 'flex';
    } 
    // Se Ativo (Aprovado): Mostra Relatório
    else if(status === 'ativo') {
        toolbar.innerHTML = `
            <button class="btn-tool" onclick="window.abrirConferenciaLote(${id})" style="background:var(--success);"><i class="ph-bold ph-check-circle"></i> Conferir / Devolver</button>
        `;
        toolbar.style.display = 'flex';
    }
    // Se Pendente: Mostra Tudo
    else {
        // CORRIGIDO: Botão "Novo Produto" sem o '+' duplicado no texto
        toolbar.innerHTML = `
            <button class="btn-tool delete" onclick="window.excluirLoteAtual()" title="Excluir Lote"><i class="ph-bold ph-trash"></i> Excluir Lote</button>
            <button class="btn-tool scan" onclick="window.iniciarEscaneamentoIA()"><i class="ph-bold ph-camera"></i> Scanear</button>
            <button class="btn-tool gold" onclick="window.adicionarItemManualLote()"><i class="ph-bold ph-plus"></i> Novo Produto</button>
            <button class="btn-tool approve" id="btnAprovarLote"><i class="ph-bold ph-check-circle"></i> Aprovar</button>
        `;
        toolbar.style.display = 'flex';
    }
    
    document.getElementById('barLoteActions').style.display = 'none';
    document.getElementById('checkAllLote').checked = false;

    carregarItensDoLote(id);
};

// --- ALTERAÇÃO: EXCLUSÃO DE LOTE USA O MESMO MODAL HTML ---
window.excluirLoteAtual = function() {
    if(!window.loteAtualId) return;
    window.solicitarExclusao(window.loteAtualId, 'lote');
};

// --- FUNÇÃO PARA ATUALIZAR ITEM NO LOTE (GENÉRICO) ---
window.atualizarItemLote = async function(id, campo, valor, inputElement) {
    if (window.loteAtualStatus === 'ativo' || window.loteAtualStatus === 'finalizado') {
        inputElement.value = inputElement.getAttribute('data-original'); 
        return Toast.fire({ icon: 'warning', title: 'Lote fechado. Não pode editar.' });
    }

    if (campo === 'preco_custo' || campo === 'preco_venda') {
        const parsedValue = parseDecimalInput(valor);
        if (parsedValue < 0) return;
        valor = parsedValue;
    } else if (!valor && valor !== 0 && valor !== '0') {
        return;
    }

    inputElement.style.backgroundColor = "#fffde7";

    try {
        const body = { id: id };
        body[campo] = valor;

        await fetch(API.lote_itens, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });

        inputElement.style.backgroundColor = "#e6fffa";
        if (campo === 'preco_custo' || campo === 'preco_venda') {
            const formatted = formatDecimal(valor);
            inputElement.value = formatted;
            inputElement.setAttribute('data-original', formatted);
        } else {
            inputElement.setAttribute('data-original', valor);
        }
        setTimeout(() => {
            inputElement.style.backgroundColor = 'transparent';
            if (document.activeElement !== inputElement) {
                 inputElement.style.border = '1px solid transparent';
            }
        }, 1000);
        
        if(['preco_custo', 'preco_venda', 'quantidade'].includes(campo)) {
            // Recarrega lista para atualizar totais
            carregarItensDoLote(window.loteAtualId);
        }
    } catch(e) {
        inputElement.style.backgroundColor = "#ffebee";
        Toast.fire({icon: 'error', title: 'Erro ao salvar'});
    }
};

// --- FUNÇÕES DE CHECKBOX NO LOTE ---
window.toggleCheckAllLote = function(source) {
    const checkboxes = document.querySelectorAll('.check-lote');
    checkboxes.forEach(cb => cb.checked = source.checked);
    atualizarBarraAcoesLote();
};

window.atualizarBarraAcoesLote = function() {
    const count = document.querySelectorAll('.check-lote:checked').length;
    const bar = document.getElementById('barLoteActions');
    document.getElementById('qtdSelLote').innerText = count;
    bar.style.display = count > 0 ? 'flex' : 'none';
};

// --- ALTERAÇÃO: EXCLUSÃO EM MASSA USA O MESMO MODAL HTML ---
window.excluirMassaLote = async function() {
    const checked = document.querySelectorAll('.check-lote:checked');
    if (checked.length === 0) return;
    const ids = Array.from(checked).map(cb => cb.value);
    window.solicitarExclusao(null, 'lote_massa', ids);
};

// --- APROVAR LOTE (USANDO O NOVO MODAL CENTRALIZADO) ---
window.aprovarLoteAtual = async function() {
    if (!window.loteAtualId) return;

    Swal.fire({
        title: 'Aprovar Lote?',
        text: "Isso enviará todos os produtos para o estoque oficial e finalizará a edição deste lote.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#00b894', 
        cancelButtonColor: '#2d3436', 
        confirmButtonText: 'Sim, Aprovar',
        cancelButtonText: 'Revisar'
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Aprovando...', didOpen: () => Swal.showLoading() });
            
            try {
                const res = await fetch(API.aprovar_lote, {
                    method: 'POST',
                    body: JSON.stringify({ lote_id: window.loteAtualId })
                });
                const json = await res.json();
                
                if (json.success) {
                    Swal.close(); 

                    // --- ATIVA O MODAL IGUAL AO LOGIN ---
                    const modal = document.getElementById('modalSucesso');
                    document.getElementById('msgSucesso').innerText = 'Lote Aprovado com Sucesso!';
                    openModal('modalSucesso');

                    setTimeout(() => {
                        closeModal('modalSucesso');
                        
                        // ATUALIZAÇÃO SEM REDIRECIONAR:
                        window.loteAtualStatus = 'ativo';
                        window.abrirLote(window.loteAtualId, 'ativo');
                        carregarProdutos(); // Atualiza estoque em background
                    }, 2500);

                } else {
                    Swal.fire('Erro', json.error || 'Erro ao aprovar', 'error');
                }
            } catch (e) {
                Swal.fire('Erro', 'Falha na conexão', 'error');
            }
        }
    });
};

// --- CONTROLE DE QUANTIDADE PERSONALIZADO ---
window.ajustarQtd = function(id, delta, btn) {
    if (window.loteAtualStatus === 'ativo' || window.loteAtualStatus === 'finalizado') {
        return Toast.fire({ icon: 'warning', title: 'Lote fechado.' });
    }

    const wrapper = btn.closest('.qty-wrapper');
    const input = wrapper.querySelector('input');
    let novaQtd = (parseInt(input.value) || 0) + delta;
    
    if (novaQtd < 1) novaQtd = 1;
    input.value = novaQtd;
    
    // Atualiza no banco
    window.atualizarItemLote(id, 'quantidade', novaQtd, input);
};

// --- FUNÇÃO CORRIGIDA: LÓGICA DE FINALIZADOS VS PENDENTES ---
function carregarItensDoLote(id) {
    const tbody = document.getElementById('listaItensLote');
    const isFinalizado = (window.loteAtualStatus === 'finalizado');
    const isLocked = (window.loteAtualStatus === 'ativo' || isFinalizado);
    const disabledAttr = isLocked ? 'disabled' : '';
    const displayCheckbox = isLocked ? 'none' : 'table-cell'; 

    const thCheck = document.querySelector('#view-lote th.col-checkbox');
    if(thCheck) thCheck.style.display = displayCheckbox;

    const inputStyleBase = "width:100%; border:1px solid transparent; background:transparent; padding:8px; border-radius:6px; font-size:13px; color:#444; text-align:center;";
    const inputStyle = isLocked ? `${inputStyleBase} opacity: 0.8; cursor: not-allowed;` : `${inputStyleBase} transition:0.2s;`;
    const codeStyle = `${inputStyle} font-family:'Courier New', monospace; font-weight:700; letter-spacing:-0.5px; background:#f9f9f9; text-align:center;`;

    tbody.innerHTML = '<tr><td colspan="7" class="center"><div class="spinner"></div></td></tr>';
    
    // SELECIONA A URL CORRETA BASEADO NO STATUS
    let url = API.lote_itens + `?lote_id=${id}&t=${new Date().getTime()}`;
    if (isFinalizado) {
        url = `../api/relatorio_lote.php?lote_id=${id}`;
    }
    
    fetch(url)
        .then(r => r.json())
        .then(data => {
            tbody.innerHTML = '';
            
            // --- CÁLCULO DOS KPIs ---
            let totalQtd = 0, qtdVendida = 0;
            let custoVendido = 0, vendaVendida = 0; 
            let custoTotal = 0, vendaTotal = 0;     

            if(isFinalizado && data.resumo) {
                totalQtd = data.resumo.qtd_total || 0;
                qtdVendida = data.resumo.qtd_vendida || 0;
                custoVendido = parseFloat(data.resumo.total_custo_vendido || 0);
                vendaVendida = parseFloat(data.resumo.total_vendido_valor || 0);
            }

            if(data.itens && data.itens.length > 0) {
                data.itens.forEach(item => {
                    // --- CORREÇÃO DE VARIAVEIS (COMPATIBILIDADE) ---
                    // Lê 'quantidade' ou 'qtd'
                    let q = parseInt(item.quantidade || item.qtd || 1);
                    // Lê 'preco_custo' ou 'custo'
                    let c = parseFloat(item.preco_custo || item.custo || 0);
                    // Lê 'preco_venda' ou 'venda'
                    let v = parseFloat(item.preco_venda || item.venda || 0);
                    
                    if(!isFinalizado) {
                        totalQtd += q;
                        custoTotal += c * q;
                        vendaTotal += v * q;
                    }
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="center" style="padding:40px; color:#999;">Nenhum item encontrado.</td></tr>';
            }

            // ATUALIZAÇÃO DOS CARDS (KPIs)
            const kpiItens = document.querySelector('.kpi-mini:nth-child(1)');
            const kpiCusto = document.querySelector('.kpi-mini:nth-child(2)');
            const kpiVenda = document.querySelector('.kpi-mini:nth-child(3)');
            const kpiLucro = document.querySelector('.kpi-mini:nth-child(4)');

            if (isFinalizado) {
                kpiItens.querySelector('label').innerText = 'Total / Vendidos';
                document.getElementById('loteQtd').innerText = `${totalQtd} / ${qtdVendida}`;

                kpiCusto.querySelector('label').innerText = 'Acerto (Pagar)';
                document.getElementById('loteCusto').innerText = formatMoney(data.resumo ? data.resumo.total_pagar : 0);

                kpiVenda.querySelector('label').innerText = 'Faturamento Real';
                document.getElementById('loteVenda').innerText = formatMoney(vendaVendida);

                kpiLucro.querySelector('label').innerText = 'Lucro Real';
                let lucroReal = vendaVendida - (data.resumo ? parseFloat(data.resumo.total_pagar) : 0);
                document.getElementById('loteLucro').innerText = formatMoney(lucroReal);
            } else {
                kpiItens.querySelector('label').innerText = 'Itens';
                document.getElementById('loteQtd').innerText = totalQtd;

                kpiCusto.querySelector('label').innerText = 'Custo Total';
                document.getElementById('loteCusto').innerText = formatMoney(custoTotal);

                kpiVenda.querySelector('label').innerText = 'Venda Total';
                document.getElementById('loteVenda').innerText = formatMoney(vendaTotal);

                kpiLucro.querySelector('label').innerText = 'Lucro Previsto';
                document.getElementById('loteLucro').innerText = formatMoney(vendaTotal - custoTotal);
            }

            // POPULA A TABELA
            const cats = App.data.categorias || [];
            let catOptions = '<option value="0">-</option>';
            cats.filter(c => !c.parent_id).forEach(p => {
                catOptions += `<option value="${p.id}" style="font-weight:bold">${p.nome}</option>`;
                cats.filter(f => f.parent_id == p.id).forEach(f => catOptions += `<option value="${f.id}">&nbsp;↳ ${f.nome}</option>`);
            });

            if(data.itens) {
                data.itens.forEach(item => {
                    let optionsComSelect = catOptions;
                    if(item.categoria_id) optionsComSelect = catOptions.replace(`value="${item.categoria_id}"`, `value="${item.categoria_id}" selected`);

                    const qtdControl = `
                        <div class="qty-wrapper">
                            <button class="qty-btn" onclick="window.ajustarQtd(${item.id}, -1, this)">-</button>
                            <input type="number" class="qty-input-custom" value="${item.quantidade || 1}" readonly>
                            <button class="qty-btn" onclick="window.ajustarQtd(${item.id}, 1, this)">+</button>
                        </div>
                    `;

                    // --- TRATAMENTO DOS DADOS PARA TABELA ---
                    let codDisplay = item.codigo_produto || item.codigo || '';
                    let nomeDisplay = item.nome || '';
                    let qtdDisplay = item.quantidade || item.qtd || 1;
                    let custoDisplay = item.preco_custo || item.custo || 0;
                    let vendaDisplay = item.preco_venda || item.venda || 0;
                    const custoFormatado = formatDecimal(custoDisplay);
                    const vendaFormatado = formatDecimal(vendaDisplay);
                    
                    // --- REMOVIDO COR DE FUNDO VERDE ---
                    // let rowStyle = item.vendido ? 'background-color: #f0fff4;' : ''; 

                    tbody.innerHTML += `
                        <tr>
                            <td class="col-checkbox" style="display:${displayCheckbox};">
                                <input type="checkbox" class="check-lote" value="${item.id}" onchange="window.atualizarBarraAcoesLote()" ${disabledAttr}>
                            </td>
                            <td class="col-code"><input type="text" value="${codDisplay}" onchange="window.atualizarItemLote(${item.id}, 'codigo_produto', this.value, this)" ${disabledAttr} style="${codeStyle}"></td>
                            <td><input type="text" value="${nomeDisplay}" onchange="window.atualizarItemLote(${item.id}, 'nome', this.value, this)" ${disabledAttr} style="${inputStyle} text-align:left;"></td>
                            <td class="col-category"><select onchange="window.atualizarItemLote(${item.id}, 'categoria_id', this.value, this)" ${disabledAttr} style="${inputStyle} text-align:left;">${optionsComSelect}</select></td>
                            
                            <td class="col-qty">${isLocked ? `<input value="${qtdDisplay}" disabled style="text-align:center; border:none; background:transparent;">` : qtdControl}</td>

                            <td class="col-price"><input type="text" inputmode="decimal" value="${custoFormatado}" data-original="${custoFormatado}" onchange="window.atualizarItemLote(${item.id}, 'preco_custo', this.value, this)" ${disabledAttr} style="${inputStyle}"></td>
                            <td class="col-price"><input type="text" inputmode="decimal" value="${vendaFormatado}" data-original="${vendaFormatado}" onchange="window.atualizarItemLote(${item.id}, 'preco_venda', this.value, this)" ${disabledAttr} style="${inputStyle} color:var(--success); font-weight:600;"></td>
                        </tr>
                    `;
                });
            }
        });
}

function prepararRelatorioImpressao(html) {
    const container = document.getElementById('print-only');
    if (!container) {
        window.print();
        return;
    }

    container.innerHTML = html;
    container.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(() => {
        window.print();
    });
}

window.addEventListener('afterprint', () => {
    const container = document.getElementById('print-only');
    if (container) {
        container.innerHTML = '';
        container.setAttribute('aria-hidden', 'true');
    }
});

// --- RELATÓRIO DE DEVOLUÇÃO (PREMIUM DIGITAL) ---
async function abrirConferenciaLote(loteId) {
    Swal.fire({ title: 'Carregando...', didOpen: () => Swal.showLoading() });

    try {
        const res = await fetch(`../api/relatorio_lote.php?lote_id=${loteId}`);
        const data = await res.json();
        Swal.close();

        if (!data.success) throw new Error(data.error);

        const lote = data.lote || {};
        const resumo = data.resumo || {};
        const totalEntrada = Number(resumo.qtd_total) || 0;
        const totalVendida = Number(resumo.qtd_vendida) || 0;
        const totalDevolucao = Math.max(totalEntrada - totalVendida, 0);
        const faturamento = Number(resumo.total_vendido_valor) || 0;
        const acerto = Number(resumo.total_pagar) || 0;
        const lucro = faturamento - acerto;
        const isFinalizado = lote.status === 'finalizado';
        const statusClasse = isFinalizado ? 'status-finalizado' : 'status-aprovado';
        const statusLabel = isFinalizado ? 'Finalizado' : 'Aprovado';
        const dataEntrada = lote.data_entrada ? new Date(lote.data_entrada).toLocaleDateString('pt-BR') : '-';
        const dataAprovacao = lote.data_aprovacao ? new Date(lote.data_aprovacao).toLocaleDateString('pt-BR') : '-';
        const fornecedor = lote.nome_empresa || 'Fornecedor não informado';

        let listContent = '';
        data.itens.forEach(i => {
            const qtdEntrada = Number(i.qtd_entrada) || 0;
            const qtdVendida = Number(i.qtd_vendida) || 0;
            let statusBadge = '<span class="rel-status st-estoque">Estoque</span>';

            if (qtdVendida >= qtdEntrada && qtdEntrada > 0) {
                statusBadge = '<span class="rel-status st-vendido">Vendido</span>';
            } else if (qtdVendida > 0 && qtdVendida < qtdEntrada) {
                statusBadge = '<span class="rel-status st-parcial">Parcial</span>';
            }

            listContent += `
                <div class="rel-table-row">
                    <div>
                        <span class="rel-item-name">${i.nome || '-'}</span>
                    </div>
                    <div class="rel-code">${i.codigo_produto || '-'}</div>
                    <div class="rel-qtd">${qtdEntrada} / ${qtdVendida}</div>
                    <div class="rel-money">${formatMoney(i.preco_custo || 0)}</div>
                    <div class="rel-money rel-money-highlight">${formatMoney(i.preco_venda || 0)}</div>
                    <div>${statusBadge}</div>
                </div>
            `;
        });

        // Monta o corpo completo
        let fullHtml = `
            <div class="rel-modal">
                <div class="rel-print-header">
                    <div class="rel-print-brand">
                        <img src="/img/favicon.png?v=1.2" alt="Consignei">
                        <span class="rel-print-system">Consignei</span>
                    </div>
                    <div class="rel-print-meta">
                        <span>Relatório de Conferência</span>
                    </div>
                </div>
                <div class="rel-modal-header">
                    <div class="rel-modal-title">
                        <span class="rel-status-badge ${statusClasse}">${statusLabel}</span>
                        <h3>Relatório do Lote #${lote.id}</h3>
                        <p class="rel-modal-sub">Fornecedor: <strong>${fornecedor}</strong></p>
                        <div class="rel-modal-meta">
                            <span><strong>Entrada:</strong> ${dataEntrada}</span>
                            <span><strong>Aprovação:</strong> ${dataAprovacao}</span>
                        </div>
                    </div>
                    <div class="rel-modal-summary">
                        <span class="rel-modal-summary-label">Gerado em</span>
                        <strong>${new Date().toLocaleDateString('pt-BR')}</strong>
                    </div>
                </div>

                <div class="rel-summary">
                    <div class="rel-summary-table">
                        <div class="rel-summary-cell">
                            <span>Entrada total</span>
                            <strong>${totalEntrada}</strong>
                        </div>
                        <div class="rel-summary-cell">
                            <span>Vendidos</span>
                            <strong>${totalVendida}</strong>
                        </div>
                        <div class="rel-summary-cell">
                            <span>Devolução</span>
                            <strong>${totalDevolucao}</strong>
                        </div>
                        <div class="rel-summary-cell">
                            <span>Faturamento</span>
                            <strong>${formatMoney(faturamento)}</strong>
                        </div>
                        <div class="rel-summary-cell">
                            <span>Acerto</span>
                            <strong>${formatMoney(acerto)}</strong>
                        </div>
                        <div class="rel-summary-cell rel-summary-profit">
                            <span>Lucro</span>
                            <strong>${formatMoney(lucro)}</strong>
                        </div>
                    </div>
                </div>

                <div class="rel-table">
                    <div class="rel-table-header">
                        <div>Produto</div>
                        <div>Código</div>
                        <div>Qtd (E/V)</div>
                        <div>Custo</div>
                        <div>Venda</div>
                        <div>Status</div>
                    </div>
                    <div class="rel-table-body">
                        ${listContent || '<div class="rel-table-empty">Nenhum item encontrado.</div>'}
                    </div>
                </div>
            </div>
        `;
        
        // --- USA O SWEETALERT, MAS COM HTML CUSTOMIZADO ---
        Swal.fire({
            html: fullHtml,
            width: '720px',
            padding: '25px', 
            showConfirmButton: !isFinalizado,
            showCancelButton: true,
            showDenyButton: true, 
            cancelButtonText: 'Fechar',
            denyButtonText: 'Imprimir',
            confirmButtonText: !isFinalizado ? 'Finalizar' : undefined, 
            confirmButtonColor: '#28a745', // Verde padrão
            denyButtonColor: '#2d3436',
            customClass: {
                popup: 'swal2-popup' 
            }
        }).then((result) => {
            if (result.isDenied) {
                prepararRelatorioImpressao(fullHtml);
            } else if (result.isConfirmed && !isFinalizado) {
                realizarBaixaDevolucao(loteId);
            }
        });

    } catch (error) {
        Swal.fire('Erro', error.message, 'error');
    }
};

async function realizarBaixaDevolucao(loteId) {
    // --- CONFIRMAÇÃO DE BAIXA (PADRÃO NOVO) ---
    const result = await Swal.fire({
        html: `
            <div class="modal-header">
                <div class="modal-icon icon-danger">
                    <i class="ph-bold ph-warning-octagon"></i>
                </div>
                <h3 class="modal-title">Finalizar Lote?</h3>
                <p class="modal-desc">Os itens devolvidos sairão do sistema e o lote será fechado.</p>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Sim, Finalizar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc3545', // Vermelho padrão
        cancelButtonColor: '#6c757d',
        customClass: {
            popup: 'swal2-popup'
        }
    });

    if (!result.isConfirmed) return;

    Swal.fire({ title: 'Processando...', didOpen: () => Swal.showLoading() });

    try {
        const res = await fetch('../api/baixar_devolucao_lote.php', {
            method: 'POST',
            body: JSON.stringify({ lote_id: loteId })
        });
        const json = await res.json();

        if (json.success) {
            Swal.close();
            
            // --- SUCESSO DA BAIXA (MODAL CENTRALIZADO) ---
            const modal = document.getElementById('modalSucesso');
            document.getElementById('msgSucesso').innerText = 'Lote finalizado e estoque atualizado.';
            openModal('modalSucesso');

            setTimeout(() => {
                closeModal('modalSucesso');
                
                // ATUALIZAÇÃO SEM REDIRECIONAR:
                window.loteAtualStatus = 'finalizado';
                window.abrirLote(window.loteAtualId, 'finalizado');
                carregarProdutos(); // Atualiza estoque em background
            }, 2500);

        } else {
            throw new Error(json.error);
        }
    } catch (e) {
        Swal.fire('Erro', e.message, 'error');
    }
}
