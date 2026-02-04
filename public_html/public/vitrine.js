/**
 * VITRINE JS - VERSÃO FINAL ESTÁVEL COM ZOOM
 */

const params = new URLSearchParams(window.location.search);

// Suporta URLs novas: /vitrine/<slug>  (via .htaccess) e também legado: ?loja=17
const pathParts = window.location.pathname.split('/').filter(Boolean);
let lojaSlug = null;
const vitrineIndex = pathParts.indexOf('vitrine');
if (vitrineIndex >= 0 && pathParts.length > vitrineIndex + 1) {
  lojaSlug = pathParts[vitrineIndex + 1];
}
if (!lojaSlug) {
  lojaSlug = params.get('slug');
}

const lojaId = params.get('loja');
let produtosData = [];
let carrinho = [];
let lojaInfo = {};
let categoriaAtual = 'Todas';
let tentativas = 0; 
let favoritos = new Set();

const CAMINHO_IMAGEM = '/uploads/'; 
const CATEGORIA_NOVIDADES = 'Novidades';
const TEXTURA_CLASSES = ['texture-marble', 'texture-silk', 'texture-concrete', 'texture-luxury'];

// --- SEGURANÇA ---
setTimeout(() => {
    const loading = document.getElementById('loadingOverlay');
    if (loading && loading.style.display !== 'none') {
        loading.style.display = 'none';
        if (produtosData.length === 0) console.warn("Tempo limite excedido.");
    }
}, 5000);

document.addEventListener('DOMContentLoaded', () => {
    // Inicializar badge do carrinho como escondido
    const qtdEl = document.getElementById('qtdCarrinho');
    if(qtdEl) {
        qtdEl.style.display = 'none';
    }
    configurarFiltroMobile();
    iniciar();
});

function mostrarAlertaPadrao(titulo, mensagem, tipo = 'error') {
    Swal.fire({ icon: tipo, title: titulo, text: mensagem });
}

function configurarFiltroMobile() {
    const toggle = document.getElementById('filterToggle');
    const filters = document.getElementById('toolbarFilters');
    if (!toggle || !filters) return;

    toggle.addEventListener('click', () => {
        const isOpen = filters.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
}

async function iniciar() {
    if (!lojaSlug && !lojaId) {
        mostrarErro('Link incompleto. Use /vitrine/NOME-DA-LOJA (ou, no modo antigo, ?loja=NÚMERO).');
        esconderLoading();
        return;
    }

    try {
        const timestamp = new Date().getTime();
        const url = lojaSlug
            ? `/api/dados_vitrine.php?slug=${encodeURIComponent(lojaSlug)}&t=${timestamp}`
            : `/api/dados_vitrine.php?loja=${lojaId}&t=${timestamp}`;
        
        console.log("Buscando dados em:", url);

        const res = await fetch(url);
        
        if (!res.ok) throw new Error(`Erro de conexão: ${res.status}`);

        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            if(tentativas < 2) {
                tentativas++;
                setTimeout(iniciar, 1500);
                return;
            } else {
                mostrarErro("Erro de conexão com o servidor. Por favor, atualize a página.");
                esconderLoading();
                return;
            }
        }

        if (data.error) {
            mostrarErro(data.error);
            esconderLoading();
            return;
        } else if (data.loja) {
            lojaInfo = data.loja;
            produtosData = data.produtos || [];
            
            aplicarTema(lojaInfo.tema);
            aplicarPersonalizacaoVisual(lojaInfo);
            renderHeader();
            renderBannerAviso();
            carregarFavoritos();
            popularCategorias();
            renderProdutos(produtosData);
            abrirProdutoDaUrl();

            if (produtosData.length === 0) {
                const msg = document.getElementById('msgVazio');
                if(msg) {
                    msg.innerText = "Esta loja não tem produtos disponíveis no momento.";
                    msg.style.display = 'block';
                }
            }
        }

    } catch (error) {
        console.error("Erro fatal:", error);
        if(tentativas < 2) {
            tentativas++;
            setTimeout(iniciar, 2000);
        } else {
            mostrarErro("Não foi possível carregar a loja. Verifique sua internet.");
        }
    } finally {
        if(produtosData.length > 0 || tentativas >= 2) {
            esconderLoading();
        }
    }
}

// --- FUNÇÕES VISUAIS ---

function mostrarErro(msg) {
    const div = document.getElementById('msgVazio');
    if(div) {
        div.style.display = 'block';
        div.innerHTML = `<div style="padding:15px; background:#ffebee; color:#c62828; border-radius:8px; border:1px solid #ef9a9a;">⚠️ ${msg}</div>`;
    }
}

function esconderLoading() {
    const loading = document.getElementById('loadingOverlay');
    if (loading) loading.style.display = 'none';
}

function aplicarTema(tema) {
    const root = document.documentElement;
    if (tema === 'rose') root.style.setProperty('--gold', '#e91e63');
    else if (tema === 'dark') { root.style.setProperty('--gold', '#333'); root.style.setProperty('--header-bg', '#fff'); }
    else if (tema === 'marble') root.style.setProperty('--gold', '#7f8c8d');
    else if (tema === 'blue') root.style.setProperty('--gold', '#2196f3');
    else if (tema === 'gold') root.style.setProperty('--gold', '#d4af37');
    else root.style.setProperty('--gold', '#d4af37');
}

function aplicarPersonalizacaoVisual(config) {
    const root = document.documentElement;
    const body = document.body;
    const estiloFonte = (config.estilo_fonte || 'classico').toLowerCase();
    const corFundo = config.cor_fundo || '#ffffff';
    const textura = (config.textura_fundo || '').toLowerCase();

    const estilosFonte = {
        classico: {
            familia: "'Playfair Display', serif",
            google: 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&display=swap'
        },
        moderno: {
            familia: "'Montserrat', sans-serif",
            google: 'https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;600;700&display=swap'
        },
        romantico: {
            familia: "'Dancing Script', cursive",
            google: 'https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;600;700&display=swap'
        }
    };

    const fonte = estilosFonte[estiloFonte] || estilosFonte.classico;
    carregarFonteGoogle(fonte.google);
    root.style.setProperty('--font-family', fonte.familia);
    root.style.setProperty('--font-title', fonte.familia);
    root.style.setProperty('--font-body', fonte.familia);
    root.style.setProperty('--bg-color', corFundo);

    if (body) {
        body.classList.remove(...TEXTURA_CLASSES);
        const texturaClasse = obterClasseTextura(textura);
        if (texturaClasse) {
            body.classList.add(texturaClasse);
        }
    }

    const contraste = calcularCorContraste(corFundo);
    root.style.setProperty('--text-color', contraste);
}

function carregarFonteGoogle(url) {
    if (!url) return;
    const existing = document.getElementById('vitrineGoogleFont');
    if (existing && existing.getAttribute('href') === url) return;
    const link = existing || document.createElement('link');
    link.id = 'vitrineGoogleFont';
    link.rel = 'stylesheet';
    link.href = url;
    if (!existing) document.head.appendChild(link);
}

function obterClasseTextura(valor) {
    switch (valor) {
        case 'marble':
            return 'texture-marble';
        case 'silk':
            return 'texture-silk';
        case 'concrete':
            return 'texture-concrete';
        case 'luxury':
            return 'texture-luxury';
        default:
            return '';
    }
}

function calcularCorContraste(hex) {
    const cor = (hex || '').replace('#', '').trim();
    if (cor.length !== 6) return '#111111';
    const r = parseInt(cor.substring(0, 2), 16) / 255;
    const g = parseInt(cor.substring(2, 4), 16) / 255;
    const b = parseInt(cor.substring(4, 6), 16) / 255;
    const luminancia = 0.2126 * r + 0.7152 * g + 0.0722 * b;
    return luminancia < 0.5 ? '#ffffff' : '#222222';
}

function getFavoritosKey() {
    return `vitrine_favoritos_${lojaSlug || lojaId || 'default'}`;
}

function carregarFavoritos() {
    try {
        const dados = JSON.parse(localStorage.getItem(getFavoritosKey()) || '[]');
        favoritos = new Set(dados);
    } catch (error) {
        favoritos = new Set();
    }
}

function salvarFavoritos() {
    localStorage.setItem(getFavoritosKey(), JSON.stringify(Array.from(favoritos)));
}

function isFavorito(id) {
    return favoritos.has(Number(id));
}

function toggleFavorito(id) {
    const numericId = Number(id);
    if (favoritos.has(numericId)) {
        favoritos.delete(numericId);
    } else {
        favoritos.add(numericId);
    }
    salvarFavoritos();
    filtrar();
}

function renderBannerAviso() {
    const bannerExistente = document.getElementById('bannerAviso');
    if (!lojaInfo.banner_aviso) {
        if (bannerExistente) bannerExistente.remove();
        return;
    }

    const banner = bannerExistente || document.createElement('div');
    banner.id = 'bannerAviso';
    banner.className = 'banner-aviso';
    banner.textContent = lojaInfo.banner_aviso;

    if (!bannerExistente) {
        document.body.prepend(banner);
    }
}

function filtrarProdutosAtual() {
    const input = document.getElementById('inputBusca');
    const termo = input ? input.value.toLowerCase() : '';
    return produtosData.filter(p => {
        const matchNome = p.nome.toLowerCase().includes(termo);
        const matchCat = categoriaAtual === 'Todas'
            || (categoriaAtual === 'Favoritos' && isFavorito(p.id))
            || (categoriaAtual === CATEGORIA_NOVIDADES && isProdutoRecente(p))
            || p.categoria === categoriaAtual;
        return matchNome && matchCat;
    });
}

function isProdutoNovo(produto) {
    if (isProdutoRecente(produto)) {
        return true;
    }
    const valor = produto.novo ?? produto.novidade ?? produto.is_novo ?? produto.novo_produto;
    if (typeof valor === 'string') {
        return ['1', 'true', 'sim', 'yes'].includes(valor.toLowerCase());
    }
    return valor === 1 || valor === true;
}

function isProdutoRecente(produto) {
    const dataProduto = parseDataProduto(produto?.data_criacao);
    if (!dataProduto) return false;
    const diffMs = Date.now() - dataProduto.getTime();
    const limite = 48 * 60 * 60 * 1000;
    return diffMs >= 0 && diffMs < limite;
}

function parseDataProduto(valor) {
    if (!valor) return null;
    if (typeof valor === 'number') {
        const data = new Date(valor);
        return isNaN(data.getTime()) ? null : data;
    }
    if (typeof valor === 'string') {
        const texto = valor.replace(' ', 'T');
        const data = new Date(texto);
        return isNaN(data.getTime()) ? null : data;
    }
    return null;
}

function renderHeader() {
    if(lojaInfo.nome_loja) {
        document.title = lojaInfo.nome_loja;
        const tituloEl = document.getElementById('lojaTitulo');
        if(tituloEl) tituloEl.innerText = lojaInfo.nome_loja;
        
        // Atualizar nome no header fixo
        const headerNome = document.getElementById('headerNomeLoja');
        if(headerNome) headerNome.innerText = lojaInfo.nome_loja;
    }
    
    // Atualizar ícones do WhatsApp e Instagram no header
    const headerWhatsapp = document.getElementById('headerWhatsapp');
    const headerInstagram = document.getElementById('headerInstagram');
    
    if (lojaInfo.whatsapp && headerWhatsapp) {
        const clean = lojaInfo.whatsapp.replace(/\D/g, '');
        headerWhatsapp.href = `https://wa.me/${clean}`;
        headerWhatsapp.style.display = 'flex';
    }
    
    if (lojaInfo.instagram && headerInstagram) {
        let clean = lojaInfo.instagram.trim().replace('@','').replace('/','');
        headerInstagram.href = `https://instagram.com/${clean}`;
        headerInstagram.style.display = 'flex';
    }
}

function popularCategorias() {
    const cats = ['Todas', CATEGORIA_NOVIDADES, 'Favoritos', ...new Set(produtosData.map(p => p.categoria).filter(c => c))];
    // Popular dropdown do header
    const lista = document.getElementById('dropdownList');
    if(lista) {
        lista.innerHTML = cats.map(c => `
            <div class="dropdown-item" onclick="filtrarCategoria('${c}')">${c}</div>
        `).join('');
    }
}

function toggleDropdown() {
    const d = document.getElementById('dropdownList');
    if(d) d.classList.toggle('show');
}

function filtrarCategoria(cat) {
    categoriaAtual = cat;
    const catSel = document.getElementById('catSelected');
    if(catSel) catSel.innerText = cat;
    const drop = document.getElementById('dropdownList');
    if(drop) drop.classList.remove('show');
    filtrar();
}
function resolverImagem(img) {
  if (!img) return 'https://via.placeholder.com/400x400?text=Sem+Foto';

  // Se já é URL completa
  if (/^https?:\/\//i.test(img)) return img;

  // Se já começa com /, já está ok
  if (img.startsWith('/')) return img;

  // Se vem como "uploads/arquivo.jpg"
  if (img.startsWith('uploads/')) return '/' + img;

  // Se vem só "arquivo.jpg"
  return CAMINHO_IMAGEM + img;
}
function renderProdutos(lista) {
    const grid = document.getElementById('listaProdutos');
    const msg = document.getElementById('msgVazio');
    
    if(!grid) return;

    if(lista.length === 0) {
        grid.innerHTML = '';
        if(msg && !msg.innerHTML.includes('⚠️')) msg.style.display = 'block';
        return;
    }
    
    if(msg) msg.style.display = 'none';
    
    // Limpa o grid e renderiza os produtos
    grid.innerHTML = lista.map((p, index) => {
        const imgUrl = resolverImagem(p.imagem);

        const badges = [];
        if (Number(p.quantidade) === 1) {
            badges.push('<span class="badge-tag ultima">Última Peça</span>');
        }
        if (isProdutoNovo(p)) {
            badges.push('<span class="badge-tag novo">NOVIDADE</span>');
        }
        const badgeHtml = badges.length ? `<div class="badge-group">${badges.join('')}</div>` : '';
        const favoritoAtivo = isFavorito(p.id);

        // Escapa aspas simples no nome e URL para evitar problemas
        const nomeEscapado = p.nome.replace(/'/g, "&#39;");
        const urlEscapada = imgUrl.replace(/'/g, "&#39;");

        return `
        <div class="card" style="animation-delay: ${(index % 6) * 0.05 + 0.1}s;">
            <div class="img-container">
                ${badgeHtml}
                <div class="card-actions">
                    <button class="card-action-btn ${favoritoAtivo ? 'active' : ''}" aria-label="Favoritar" onclick="toggleFavorito(${p.id})">
                        <i class="ph ph-heart${favoritoAtivo ? ' ph-fill' : ''}"></i>
                    </button>
                    <button class="card-action-btn" aria-label="Compartilhar" onclick="compartilharProduto(${p.id})">
                        <i class="ph ph-share-network"></i>
                    </button>
                </div>
                <img src="${imgUrl}" onerror="this.src='https://via.placeholder.com/400x400?text=Sem+Foto'" loading="lazy" onclick="abrirZoom('${urlEscapada}')">
            </div>
            <div class="info">
                <h3>${nomeEscapado}</h3>
                <div class="preco">${parseFloat(p.preco).toLocaleString('pt-BR', {style:'currency', currency:'BRL'})}</div>
                <button class="btn-add" onclick="addCarrinho(${p.id})">Adicionar à Sacola</button>
            </div>
        </div>
        `;
    }).join('');
}

function filtrar() {
    const filtrados = filtrarProdutosAtual();
    const selectOrdenacao = document.getElementById('ordenacao');
    const ordem = selectOrdenacao ? selectOrdenacao.value : 'padrao';

    if (ordem === 'menor') {
        filtrados.sort((a, b) => parseFloat(a.preco) - parseFloat(b.preco));
    } else if (ordem === 'maior') {
        filtrados.sort((a, b) => parseFloat(b.preco) - parseFloat(a.preco));
    }

    renderProdutos(filtrados);
}

// --- CARRINHO ---

function addCarrinho(id) {
    const p = produtosData.find(x => x.id == id);
    if(p) {
        carrinho.push(p);
        atualizarCarrinho();
        if(event && event.target) {
            const btn = event.target;
            const original = btn.innerText;
            btn.innerText = "Adicionado!";
            btn.style.background = "#25D366";
            setTimeout(() => { btn.innerText = original; btn.style.background = ""; }, 1000);
        }
    }
}

function atualizarCarrinho() {
    const qtdEl = document.getElementById('qtdCarrinho');
    if(qtdEl) {
        qtdEl.innerText = carrinho.length;
        // Esconder badge se estiver vazio
        if(carrinho.length === 0) {
            qtdEl.style.display = 'none';
        } else {
            qtdEl.style.display = 'flex';
        }
    }
    
    const container = document.getElementById('itensCarrinho');
    const totalEl = document.getElementById('totalCarrinho');
    
    let total = 0;
    
    if (carrinho.length === 0) {
        container.innerHTML = '<p style="text-align: center; color: #999; margin-top: 50px;">Sua sacola está vazia.</p>';
        if(totalEl) totalEl.innerText = 'R$ 0,00';
        return;
    }
    
    container.innerHTML = carrinho.map((item, index) => {
        total += parseFloat(item.preco);
        let imgUrl = 'https://via.placeholder.com/50';
        if (item.imagem) {
            imgUrl = resolverImagem(item.imagem);
        }

        return `
            <div class="item-carrinho">
                <img src="${imgUrl}" class="item-thumb" onerror="this.src='https://via.placeholder.com/50'">
                <div style="flex:1">
                    <div style="font-weight:600; font-size:13px; margin-bottom:4px;">${item.nome}</div>
                    <div style="color:var(--gold); font-size:13px;">${parseFloat(item.preco).toLocaleString('pt-BR', {style:'currency', currency:'BRL'})}</div>
                </div>
                <button onclick="remover(${index})" style="border:none; background:none; color:#ff4d4d; cursor:pointer; padding:5px;"><i class="ph ph-trash" style="font-size:18px;"></i></button>
            </div>
        `;
    }).join('');
    
    if(totalEl) totalEl.innerText = total.toLocaleString('pt-BR', {style:'currency', currency:'BRL'});
}

function remover(index) {
    carrinho.splice(index, 1);
    atualizarCarrinho();
}

function toggleCarrinho(show) {
    if (show) {
        openModal('modalCarrinho');
    } else {
        closeModal('modalCarrinho');
    }
}

function compartilharProduto(id) {
    const shareUrl = new URL(window.location.href);
    shareUrl.searchParams.set('produto', id);

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(shareUrl.toString())
            .then(() => Swal.fire({ icon: 'success', title: 'Link copiado!', text: 'Compartilhe o produto com quem quiser.', timer: 1800, showConfirmButton: false }))
            .catch(() => Swal.fire({ icon: 'error', title: 'Erro', text: 'Não foi possível copiar o link.' }));
    } else {
        const tempInput = document.createElement('input');
        tempInput.value = shareUrl.toString();
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        tempInput.remove();
        Swal.fire({ icon: 'success', title: 'Link copiado!', text: 'Compartilhe o produto com quem quiser.', timer: 1800, showConfirmButton: false });
    }
}

function abrirProdutoDaUrl() {
    const produtoId = params.get('produto');
    if (!produtoId) return;

    const produto = produtosData.find(item => String(item.id) === String(produtoId));
    if (produto) {
        const imagem = resolverImagem(produto.imagem);
        abrirZoom(imagem);
    }
}

function enviarWhatsApp() {
    if (carrinho.length === 0) {
        mostrarAlertaPadrao('Atenção', 'Sua sacola está vazia.');
        return;
    }
    
    let msg = `*${lojaInfo.nome_loja || 'LOJA'} - RECIBO DE COMPRA*\n\n`;
    msg += `*Itens:*\n`;
    let total = 0;
    carrinho.forEach((item, index) => {
        const preco = parseFloat(item.preco);
        total += preco;
        const codigo = item.codigo_produto || 'S/C';
        msg += `${index + 1}. ${item.nome} (Cód: ${codigo}) - ${preco.toLocaleString('pt-BR', {style:'currency', currency:'BRL'})}\n`;
    });
    msg += `\n*Total:* ${total.toLocaleString('pt-BR', {style:'currency', currency:'BRL'})}\n`;
    msg += `\nQual a melhor forma de pagamento para você?`;
    
    const cleanWhats = lojaInfo.whatsapp ? lojaInfo.whatsapp.replace(/\D/g, '') : '';
    if(!cleanWhats) {
        mostrarAlertaPadrao('Atenção', 'Esta loja não configurou um número de WhatsApp.');
        return;
    }
    
    window.open(`https://wa.me/${cleanWhats}?text=${encodeURIComponent(msg)}`, '_blank');
}

// --- FUNÇÕES DE ZOOM (NOVA) ---

function abrirZoom(url) {
    const modal = document.getElementById('modalZoom');
    const img = document.getElementById('imgZoomFull');
    if(modal && img) {
        img.src = url;
        openModal('modalZoom');
    }
}

function fecharZoom() {
    closeModal('modalZoom');
}

document.addEventListener('keydown', (e) => {
    if(e.key === 'Escape') {
        // Fechar dropdown se estiver aberto
        const drop = document.getElementById('dropdownList');
        if(drop && drop.classList.contains('show')) {
            drop.classList.remove('show');
        }
    }
});

// Fechar dropdown ao clicar fora
document.addEventListener('click', (e) => {
    const dropdown = document.getElementById('dropdownList');
    const dropdownBtn = document.querySelector('.dropdown-btn');
    
    if(dropdown && dropdown.classList.contains('show')) {
        if(!dropdown.contains(e.target) && dropdownBtn && !dropdownBtn.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    }
});

// COMPORTAMENTO DE SCROLL: Esconder/Mostrar Header (no topo)
let lastScrollTop = 0;
let scrollTimeout;

window.addEventListener('scroll', () => {
    const header = document.getElementById('headerFixo');
    if(!header) return;
    
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    
    // Se estiver rolando para baixo e já passou 100px - esconder header
    if(scrollTop > lastScrollTop && scrollTop > 100) {
        header.classList.add('hidden');
    } 
    // Se estiver rolando para cima - mostrar header
    else if(scrollTop < lastScrollTop) {
        header.classList.remove('hidden');
    }
    
    lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;
    
    // Garantir que o header apareça se estiver no topo
    if(scrollTop <= 50) {
        header.classList.remove('hidden');
    }
}, false);

// Melhorar feedback de busca em tempo real
let buscaTimeout;
const inputBusca = document.getElementById('inputBusca');
if(inputBusca) {
    inputBusca.addEventListener('input', () => {
        clearTimeout(buscaTimeout);
        buscaTimeout = setTimeout(() => {
            filtrar();
        }, 300); // Aguarda 300ms após parar de digitar
    });
}

const btnAbrirCarrinho = document.getElementById('btnAbrirCarrinho');
if (btnAbrirCarrinho) {
    btnAbrirCarrinho.addEventListener('click', () => toggleCarrinho(true));
}
