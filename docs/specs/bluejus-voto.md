# BlueJus Voto: eleições e votações on-line

**Data:** 09/11/2026, conferida contra o master `940e82dfd` (depois da v2.42.0).
**Risco:** **ALTO** nas fases de identidade do eleitor, voto, urna e apuração (B, C e E da seção 6): abre rota pública
sem login, cria uma identidade nova (o eleitor, que não é `User`) e guarda dado cujo sigilo é a razão do produto.
**MÉDIO** na fase A (permissão nova e recurso do Master). Pelo ciclo do projeto: spec (este documento), revisão
contra a spec em cada PR e re-revisão antes de seguir nos PRs ALTO.
**Status:** fase 1, análise. Não existe código, tabela nem migration desta frente. Decisões D1, D2, D3, D5, D6, D7
e o cabeçalho tomadas pelo Coordenador em 09/11/2026 (seção 3.0). Pendentes com o Samuel: D4, cliente piloto,
contratação do provedor de e-mail e D8. A fase A começa pelo que não depende de D8.

**Desenho:** `docs/design/redesign-08-11-26/18 - Voto - VS 08.11.26.dc.html` (Artefato original:
`claude.ai/code/artifact/3c9fe618-65e4-457f-8d3d-c31bb68901c8`), com `bj-voto-nucleo.js`, `bj-voto-testes.js`,
`bj-voto-entrada.js`, `bj-voto-boletim.js`, `bj-voto-cenarios.js`, logos e `support.js` na mesma pasta.
**Documento do autor:** `docs/design/redesign-08-11-26/ESPEC_VOTO.md` (viabilidade, arquitetura, riscos, roteiro, OAB/DF).

**Anexos desta spec** (leitura integral de 09/11/2026, base da conferência ✓/✗ de cada PR):

- [bluejus-voto-anexo-telas.md](bluejus-voto-anexo-telas.md): inventário do desenho por região e tela, com valores
  de CSS, estados `sc-if`, textos literais, sons, teclado, animações e defeitos do próprio desenho.
- [bluejus-voto-anexo-protocolo.md](bluejus-voto-anexo-protocolo.md): modelo de dados, estados e transições,
  protocolo passo a passo, limites numéricos, os 97 testes do protótipo (todos passam no Node 22) e 20 achados.

**Relacionadas:** `perfis-1.2-telas-e-recursos.md` (Master liga e desliga recurso), `mcp-remoto-oauth.md`
(precedente de "mesma aplicação, dois papéis"), `login-2fa-totp.md` (2FA ainda não existe),
`master-seccionais-oab.md`, `docs/AUTORIZACAO.md`.

---

## 0. Resumo para o Samuel

1. O desenho é um produto inteiro: criar eleição, montar a equipe, importar a lista de quem pode votar, identificar
   o eleitor, votar numa "urna" na tela, apurar com guardiões da chave, publicar resultado verificável e relatórios.
2. Hoje tudo roda no navegador de quem abre o desenho, com os dados no próprio navegador. O ESPEC do Farlei já diz:
   **o protótipo não pode conduzir eleição real**.
3. Do BlueJus o Voto reaproveita login da equipe, escritório, liga e desliga do Master, filas, agendador, PDF, QR Code,
   armazenamento de arquivos e limite de acessos. É novo: eleitor sem conta, câmera, criptografia do voto, urna,
   guardiões, apuração, verificador e trilha própria.
4. A criptografia do protótipo usa peças maduras do navegador (WebCrypto), mas a parte mais delicada (assinatura
   cega e divisão da chave entre guardiões) foi escrita à mão. Isso precisa trocar por padrão publicado e passar
   por revisão de especialista de fora antes de qualquer votação de verdade.
5. Estimativa: **cerca de 75 dias de implementação, 90 com revisão e correções**, em 20 PRs pequenos. Revisão
   criptográfica externa, teste de intrusão e prova de carga ficam fora dessa conta (dependem de contratação).
6. Eleição oficial da OAB depende de convênio, credenciamento e requisitos que o BlueJus ainda não tem (seção 5).
   O caminho realista é começar por votações institucionais (assembleia, associação), como o próprio ESPEC propõe.

---

## 1. O que o desenho mostra, por etapa

Números do arquivo: 163 estados escondidos (`sc-if`), 51 repetições (`sc-for`), 110 botões, 7 sons, 7 animações,
1 ponto de responsivo (`max-width:760px`) e `prefers-reduced-motion`. Nenhum travessão no desenho nem nos JS.
Detalhe de cada item, com valores: anexo de telas.

### 1.1 Estrutura e visual

- **Cabeçalho** fixo azul `#0a76a8`, 64 px, logo branco (leva ao Expediente), rótulo "Voto", nome do usuário em
  caixa alta. Menu: EXPEDIENTE, DEMANDAS, PONTO, ASSINATURA, VOTO (ativo). Os links para `03 - EXPEDIENTE` e
  `15 - Demandas` apontam para arquivos que estão em `redesign-04-11-26`, não nesta pasta. **Não entra** nas telas da
  equipe: elas usam a moldura do sistema (decisão de 09/11/2026, seção 3.0).
- **Faixa amarela permanente** (`.vAviso`): "**Ambiente demonstrativo.** Dados e eleitores são fictícios. Não é
  votação oficial de nenhuma entidade e o sistema não possui certificação de órgão eleitoral."
- **Abas:** Votar, Resultados, Acompanhar e Gestão eleitoral (as duas últimas só para quem gere). Aba escondida
  "Cadastro para votar".
- **Base visual:** Arial; fundo `#f3f6f8`; texto `#12242f`/`#34505f`; primária `#0a6fa0` (hover `#3b8fc2`);
  títulos `#0b3550`; sucesso `#1e7b45`; erro `#a3232b`; alerta `#8a6200`; borda `#d3dde4`; raio 3 px.
- **Componentes do próprio desenho** (entram como estão, nunca os do AdminLTE/Bootstrap): cartão `.vC` com barra
  `.vH`, botões `.vB` 36 px (`.vP` primário, `.vD` perigo), campo `.vF`, rótulo `.vL`, aba `.vTab`, indicador `.vKpi`
  (borda esquerda 4 px), chip `.vChip`, linha `.vLin`, pílulas de etapa `.vPas`, ladrilho `.vCat`, caixa de foto
  `.vFoto`, modal `.vMod` (fundo `rgba(11,53,80,.55)`, 720 px), toast verde, vermelho ou azul por 3,6 s, e a urna
  `.u*` inteira desenhada.

### 1.2 Etapa 1: preparar a eleição (equipe logada)

| Tela | O que mostra e faz |
|---|---|
| Criar eleição (Comissão) | Assistente de 7 etapas com pílulas: Categoria (9 cenários, de "Assembleia de condomínio" a "Personalizado"), Regras e prazo, Acesso, Cargos, Lista de aptos, Equipe, Revisar. Salva rascunho a cada avanço e valida. "Salvar e sair". "Publicar votação" abre o modal de Link e QR Code. |
| Perfis (Administração) | Equipe padrão e equipe por votação ou evento. "Aplicar cargos da categoria", "Designar" (nome, e-mail, cargo, uma de 7 permissões). Equipe própria precisa de ao menos 3 guardiões para publicar. |
| Lista de aptos (Comissão) | Importa XLSX ou CSV, "Baixar modelo", busca, relatório (inválidas, duplicadas, pesos raros), "Registrar" ocorrência para quem está inapto. Lista bloqueia na abertura. |
| Painel | 5 indicadores, "Eventos com mais de uma votação", e por votação 6 passos (Criar, Lista de aptos, Publicar, Votação, Apuração, Relatório), próximo passo e barra de participação. |
| Link e QR Code (modal) | QR, link `/voto/e/{id}` ou `/voto/v/{evento}`, código de conferência `XXXX-XXXX`; "Copiar link", "Baixar cartaz com QR Code" (PNG 1000x1414), "Testar acesso", "Abrir terminal presencial". |

Cenários (`bj-voto-cenarios.js`): condomínio, entidade de classe, associação, sindicato, cooperativa, sócios,
ensino, consulta, personalizado. Cada um pré-preenche rótulos, documentos, modalidade (chapa, pauta, individual,
consulta), peso, acesso e critério; tudo editável.

### 1.3 Etapa 2: identificar o eleitor (portal público, sem conta no BlueJus)

| Tela | O que mostra e faz |
|---|---|
| Portal do eleitor | Cabeçalho branco, pílulas "1. Localizar seu nome / 2. Confirmar identidade / 3. Votar / 4. Comprovante". Começa por "Localize seu nome para votar". |
| Acesso por código | E-mail com 6 números, válido 10 min, 5 tentativas, 5 pedidos por hora, 15 min para concluir, sessão de 1 h. No primeiro acesso: documento, selfie, consentimento LGPD e declaração do art. 299 do Código Penal. Vota logo; a conferência humana é depois ("Revisão posterior"). |
| Acesso por PIN | Identificação e PIN. Sem cadastro, vai a "Cadastro para votar": até 4 fotos por categoria, PIN duas vezes, "Enviar para análise" ou "Enviar e votar". PIN com PBKDF2 e bloqueio no 5º erro. |
| Eleitores (Validação) | Fotos com nota de qualidade, localização, 3 caixas de conferência, "Habilitar" e "Recusar" com motivo. |
| Avaliações (Conselho) | Ocorrências com justificativa (até 160 caracteres): "Manter inaptidão" ou "Tornar apto(a)". |

A análise de qualidade da foto (resolução, escuro, luz demais, foco, corte, distância, reflexo, 3 tentativas com
ressalva) roda no navegador; não confere autenticidade nem compara rosto.

### 1.4 Etapa 3: votar

| Tela | O que mostra e faz |
|---|---|
| Votar | Um cartão por votação, mensagem do estado ("Você já votou nesta votação.", "Sessão suspensa até {data}.", "Encerra em mm:ss."), "Conhecer candidaturas" (modal), "Simular votação" e "Votar". |
| Cabine | PIN (se não entrou); "Aperte CONFIRMA para iniciar"; "SEU VOTO PARA" com caixas de dígitos, foto e nome; "VOTO EM BRANCO", "ABSTENÇÃO", "NÚMERO ERRADO / VOTO NULO"; "Protegendo seu voto"; "FIM" com o código de depósito BJV; "Próxima votação" do mesmo evento; tela de erro "Voto não registrado". |
| Teclado | Teclado de tela com braille e teclado físico (0 a 9, B, Backspace, Enter). Teclas BRANCO, CORRIGE, CONFIRMA. |
| Som e movimento | 7 sons (AudioContext, liga e desliga), LEDs piscando, faixas "SIMULAÇÃO" e "VOTO ABERTO", animações `uEntra`, `uSobe`, `uSur`, `uDig`, `uPisca`, `uRing`, `uBarra`; tudo parado com `prefers-reduced-motion`. |
| Comprovante | Comprovante de participação (código BJC, sem o voto) em imagem e por e-mail; 2ª via traz só a faixa horária. |

### 1.5 Etapa 4: conduzir e acompanhar

- **Modo programado** (abre e fecha no horário) ou **conduzido pela mesa**: 1ª e 2ª convocação com quórum próprio,
  "Abrir por minutos", "Abrir sem prazo", "Definir prazo", "Encerrar" com motivo.
- **Sessão permanente** (condomínio): próxima sessão até 60 dias, término até 90; quem votou pode trocar o voto,
  conta o último; "Retomar sessão"; "Ata parcial" com participação, nunca resultado.
- **Acompanhar**: "Assistir" abre janela ao vivo para projetar (participação e quórum, sem parcial de votos),
  "Lista nominal" (fotos, faixa horária, localização), "Documento de auditoria" em PDF.
- **Sigilo** configurável: secreto, aberto após encerrar, aberto em tempo real (faixa "VOTO ABERTO" na cabine).

### 1.6 Etapa 5: apurar e mostrar o resultado

- **Apuração**: 5 botões "Guardião X", mínimo de 3 partes; depois a Comissão clica "Abrir cédulas e totalizar".
- **Resultados**: indicadores, conferência de comprovante BJV ou BJC, "Verificar urna", "Pacote público", 3 boletins
  (abertura/zerésima, apuração, boletim de urna em bobina de 80 mm), barras do resultado, "Verificador independente".
- Regras por cargo: maioria simples, absoluta, 2/3, unanimidade; base válidos, presentes ou aptos; empate indicado
  sem decisão automática.

### 1.7 Etapa 6: registros e números

- PDFs "Relatório consolidado" (identificação, responsáveis, critérios, boletins, eleitorado, apuração, relação de
  votantes, trilha, incidentes, termo com linhas de assinatura) e "Relação de votantes" (ordem alfabética, faixa
  horária, nunca o voto).
- **Auditoria**: trilha encadeada por hash, "Verificar", "Exportar".
- **Testes**: "Executar testes", "Gerar 30 votos de teste".
- **Estatísticas** (Gestor Eleitoral Master): 11 indicadores e CSV.

### 1.8 Papéis (mapa `VIS` do desenho)

| Papel | Sub-abas | O que faz |
|---|---|---|
| Administração | Perfis, Painel | Designa a equipe; não opera a eleição |
| Comissão Eleitoral | Painel, Criar eleição, Lista de aptos, Apuração | Publica, abre, conduz a mesa, encerra, abre as cédulas, gera link e QR |
| Validação | Eleitores | Confere e habilita cadastros |
| Conselho Eleitoral | Avaliações, Painel | Decide as ocorrências |
| Guardião | Apuração | Apresenta só a própria parte da chave |
| Auditoria | Painel, Auditoria, Testes | Relatórios, documento de auditoria, lista nominal |
| Fiscal | Painel, Auditoria | Relação de votantes e relatório |
| Gestor Eleitoral Master | Estatísticas, Painel, Auditoria | Números agregados de todas as votações; não opera |

No protótipo, quem vê a Gestão é o "Master da plataforma" (e-mail fixo no `bj-recursos.js`), que escolhe o papel
num seletor "Atuando como" com a nota "Seleção apenas para demonstração".

### 1.9 O que o desenho guarda no navegador e vai para o servidor

Chaves `bj-votacao-*`: `eleicoes`, `perfis`, `identidade` (com fotos em base64, PIN e localização), `tokens`
(códigos de e-mail), `trilha`, `incidentes` e, por votação, `urna`, `aptos`, `ocorrencias`, `participacao`,
`comprovantes`, `guardioes`, `partes-apresentadas`, `autoriz-chave`, `resultado`, `nominal`, `pendente`.
Também rodam no navegador e vão para o servidor: o relógio de 5 s que abre e fecha votações, o envio do código
(hoje mostrado na tela), o comprovante por e-mail (hoje `mailto:`), a análise da foto, a leitura de CSV/XLSX, o
filtro de quem vê qual votação, PIN, limites de tentativa, boletins, PDF e QR Code (hoje de uma CDN).
**Ficam no aparelho do eleitor**, por desenho do protocolo: o token do voto, cegar e descegar, cifrar a cédula e
guardar o token para a troca de voto na sessão permanente.

---

## 2. Confronto com o BlueJus: o que reusa e o que é novo

| Necessidade do Voto | No BlueJus hoje | Onde | Uso no Voto |
|---|---|---|---|
| Login da equipe | Sim: `User`, `UserTenant`, firewall `main`, Google, `login_throttling` | `app/config/packages/security.yaml:13-45` | Comissão, Validação etc. entram com a conta do BlueJus |
| Quem organiza | Sim: `Tenant` (escritório) | `app/src/Entity/Tenant/Tenant.php:18` | A eleição pertence ao escritório; o campo "entidade" nomeia o condomínio, associação etc. (um escritório pode conduzir assembleias de vários clientes) |
| Master liga e desliga | Sim, **global**: `RecursoEstado`, catálogo fixo, página 503 | `app/src/Master/Entity/RecursoEstado.php:26`, `app/src/Master/Service/CatalogoDeRecursos.php:32,59` | Chave `voto`, prefixo de rota `voto_`. **Sem linha = ativo**: precisa de migration que já crie desligado |
| Liberar por escritório | **Não** existe genérico; só a IA (`liberadaPeloMaster`) | `app/src/Inteligencia/Entity/ConfiguracaoDeInteligencia.php:73` | Novo, no mesmo molde (D7) |
| Permissão de tela | Sim: `Permission` + migration de dados + fixture + rótulo | `app/migrations/Version20261030110000.php:26-37` | `modules.voto.view` para a Gestão |
| Papéis eleitorais por votação | **Não** | | Tabela própria (8 papéis do desenho), fora de `TenantRole` |
| Página pública sem login | Sim: `PUBLIC_ACCESS` + `ROTAS_IGNORADAS` em 2 listeners | `security.yaml:57-78`, `app/src/EventListener/TenantContextValidatorListener.php:31-84`, `TermoAceiteListener.php:34` | Portal, cabine, urna, verificador; padrão do Push compartilhado e do Cálculo verificar |
| Limite de acessos | Sim: `rate_limiter` | `app/config/packages/framework.yaml:13-49` | Por IP e por identificação em código, PIN, consulta e depósito |
| Agendador | Sim: Scheduler com lock | `app/src/Shared/Scheduler/AgendaDeTarefas.php:34` | Abrir, encerrar, prazos da mesa e da sessão permanente |
| Filas | Sim: Messenger (`async`, `comercial`, `failed`) | `app/config/packages/messenger.yaml:21-50` | Fila própria `voto` |
| E-mail | Sim, mas com **cota da Hostinger** de 2.400 em 24 h para todo o BlueJus | `app/src/Comercial/Envio/CotaDeEnvio.php:13-24` | Conflito: uma eleição de 1.000 eleitores gasta 1.000+ da cota (D6) |
| PDF | Sim: dompdf | `app/src/Shared/Service/GeradorDePdf.php:13` | Boletins (80 mm), relatório, comprovante, documento de auditoria |
| QR Code | Sim: bacon-qr-code | `app/src/TrabalhoExterno/Service/QrCodeEmSvg.php:21` | Substitui o QR da CDN do desenho |
| Arquivos | Sim: armazenamento com R2, categorias, área temporária privada | `app/src/Shared/Armazenamento/ArmazenamentoDeArquivos.php:34` | Documento e selfie em categoria privada com prazo de guarda |
| Trilha de auditoria | Sim, genérica: `AuditLog` grava a entidade inteira e o admin do escritório lê | `app/src/Service/Audit/AuditLogSubscriber.php:37` | **Não usar** para eleitor, participação e cédula; trilha própria encadeada |
| Criptografia | sodium (secretbox), openssl, HMAC | `app/src/Sync/Service/CifradorDeSegredo.php:16` | Cifra das chaves em repouso, HMAC do pseudônimo; assinatura cega é nova |
| Câmera | **Bloqueada**: `Permissions-Policy camera=()` | `nginx/conf.d/nginx.prod.conf:51,233` | Liberar `camera=(self)` só no endereço do Voto (configuração de produção) |
| Biometria, prova de vida | Não | | Fora do primeiro produto (D6) |
| Consulta OAB (CNA) | Dormente, conferência manual pelo Master | `app/src/Auth/Service/ClienteOabIndisponivel.php:10` | Não usar no primeiro produto |
| 2FA | Não (spec `login-2fa-totp.md` aguardando) | | Só exigência da OAB |
| Banco | 1 conexão, 1 entity manager, só o schema `public` | `app/config/packages/doctrine.yaml:2-4` | Schema próprio é novo (D1) |
| Deploy | 1 php, 1 worker, 1 scheduler, nginx, postgres; sem subdomínio de aplicação | `docker-compose.prod.yml` | Container e subdomínio próprios são novos (D1, D2) |
| Precedente de arquitetura | MCP remoto: "a mesma aplicação, dois papéis" | `docs/specs/mcp-remoto-oauth.md:114` | Apoia D1-B |

---

## 3. Decisões (com opções e recomendação)

### 3.0 Decisões tomadas e pendentes

Em 09/11/2026 o Coordenador decidiu pela recomendação (regra de 27/10; registro em
`bluejus-coordenacao/decisoes-09-11.md`):

| Decisão | Resultado |
|---|---|
| D1 | **B**: mesmo código, container PHP próprio `voto`, schema `voto`, endereço `voto.bluejus.com.br` |
| D2 | As 6 camadas; teto de aptos por eleição como **configuração no Master** |
| D3 | **A**: protocolo do protótipo com RFC 9474, WebCrypto, RFC 8785 e as correções da seção 4 |
| D5 | Modo demonstração para todos; "eleição real" só liberada pelo Master, por escritório, depois da revisão externa (D4) |
| D6 | Os dois acessos (código e PIN); e-mail por **transporte próprio com DSN separado** (fora da cota da Hostinger); sem biometria; prazo de guarda das fotos como configuração no Master, começando em 90 dias |
| D7 | Pela recomendação |
| Cabeçalho | **Moldura do sistema** (`base.html.twig`), como em `agenda-1a.md` e `meu-escritorio.md`: a tela é o conteúdo e o link entra no menu existente. Vale igual para Ponto e Assinatura |

Pendentes com o Samuel (não travam a fase A):

- **D4:** contratar a revisão criptográfica externa e o teste de intrusão.
- **Cliente piloto:** qual é, porque define a ordem das fases B a E (seção 6).
- **Provedor de e-mail:** contratar o serviço que o DSN do Voto vai usar.
- **D8:** as 9 correções do desenho.

### D1. Onde o Voto roda

| Opção | Como é | A favor | Contra |
|---|---|---|---|
| A | Domínio `app/src/Voto/` no mesmo app, nos mesmos containers e no schema `public` | Mais rápido (cerca de 5 dias a menos) | A carga de uma eleição divide PHP e banco com o BlueJus; contraria o ESPEC §8 ("deploy separado") |
| **B (recomendada)** | Mesmo código (`app/src/Voto/`, reaproveita login, Doctrine, filas, PDF, QR), mas: endereço próprio `voto.bluejus.com.br` servido por um **container PHP próprio** (`voto`), **schema Postgres próprio** (`voto`) sem chave estrangeira para tabelas do BlueJus, fila própria. A Gestão (equipe logada) fica no BlueJus; portal, cabine, urna e verificador ficam no container `voto` | Atende o ESPEC §8 sem duplicar login; isola a carga; o container pode mudar de máquina depois sem reescrever | Mais um container, um bloco do nginx e um certificado para o Samuel subir na VPS |
| C | Aplicação separada (outro repositório, outro banco, outra VPS); o BlueJus só põe o link no menu | Isolamento máximo | Duplica login, deploy e CI; cerca de 15 a 20 dias a mais |

**Por que B:** é o ponto em que a eleição não derruba o BlueJus e o BlueJus não precisa de uma segunda equipe de
infraestrutura. O próprio MCP remoto já escolheu "mesma aplicação, dois papéis".

### D2. Como proteger o BlueJus da carga de uma eleição

Recomendação (pressupõe D1-B), em camadas:

1. **Processo separado:** o container `voto` tem o próprio limite de processos PHP (`pm.max_children`); um pico de
   eleitores esgota o `voto`, nunca o `php` do BlueJus.
2. **Banco com teto:** o usuário do banco do Voto com `CONNECTION LIMIT`, para a eleição não tomar todas as conexões.
3. **nginx:** `limit_req` e `limit_conn` por IP no endereço do Voto; arquivos da cabine (JS, CSS, sons) estáticos com
   cache longo.
4. **Sala de espera:** acima de um número de cabines simultâneas, o eleitor espera numa fila com posição.
5. **Teto por eleição:** número máximo de aptos por eleição como **configuração no Master** (é número de negócio),
   até um teste de carga provar mais. Acima do teto, VPS dedicada para a eleição (contratação por evento).
6. **E-mail separado** da cota do BlueJus (D6).

Alternativas: só limites no mesmo container (barato, mas um pico deixa o BlueJus lento) ou VPS dedicada desde o
início (custo fixo sem cliente).

### D3. Protocolo criptográfico (biblioteca)

| Opção | Como é | A favor | Contra |
|---|---|---|---|
| **A (recomendada para o piloto institucional)** | Manter o desenho do protocolo do protótipo (assinatura cega separa quem votou do voto; cédula cifrada de tamanho fixo; guardiões; cadeia de hash; pacote público), trocando o que foi escrito à mão por padrão publicado: assinatura cega **RFC 9474** (RSABSSA-SHA384-PSS-Randomized) com `@cloudflare/blindrsa-ts` no navegador e assinatura no servidor pelo OpenSSL do PHP (o passo de assinatura da RFC é RSA sem preenchimento) com verificação PSS na urna; cédula ECDH P-256 + HKDF-SHA-256 + AES-256-GCM pelo WebCrypto, aberta no PHP com `openssl_pkey_derive`, `hash_hkdf` e `openssl_decrypt`; hash canônico **RFC 8785** para o PHP reproduzir o do navegador; chave da eleição gerada no navegador da cerimônia e entregue em partes aos guardiões (o servidor guarda só a pública e o compromisso); mais as correções da seção 4 | Entrega o desenho aprovado como está; só primitivas padrão; PHP sem extensão nova | A chave ainda é remontada num ponto na apuração e o operador do servidor é um só: aceitável só em votação institucional pequena, com observadores e o limite declarado |
| B | Contagem homomórfica própria: ElGamal sobre ristretto255 (sodium no PHP e libsodium.js), provas de conhecimento zero, decifração em limiar sem remontar a chave (modelo ElectionGuard; é o que o ESPEC §4 pede para produção) | Garantia muito mais forte; não depende de separar quem votou | Criptografia nova escrita pela equipe: cerca de 25 a 35 dias a mais e revisão externa mais cara |
| C | Usar o **Belenios** (código aberto, AGPLv3, em OCaml; contagem homomórfica, decifração em limiar, voto com peso; campanha de certificação relatada no SSTIC 2024) como motor num container à parte, com o BlueJus na gestão, identidade e telas | Protocolo publicado e analisado por terceiros; verificador existente | Equipe não domina OCaml; a cabine do desenho passa a usar a biblioteca de cédula dele; licença AGPL; assinatura cega e troca de voto mudam |

**Recomendação:** A agora, com a meta de B ou C antes de qualquer pleito da OAB (roteiro do ESPEC §10, item 3).
O ElectionGuard fica como referência de especificação; não achei sinal de manutenção recente das implementações.

### D4. Quem audita, e quando

| Opção | Como é |
|---|---|
| **A (recomendada)** | Contratar **revisão criptográfica independente** do protocolo e do código das fases C e E e **teste de intrusão** do endereço do Voto **antes do primeiro uso real**, e publicar o relatório. Candidatos a sondar: grupo universitário de criptografia aplicada ou empresa de segurança com criptógrafo na equipe. Não trava as fases A, B e D |
| B | Só revisão interna (revisores do projeto e revisão adversarial) e piloto com observadores. Barato, mas não sustenta nenhuma afirmação de segurança ao cliente (ESPEC §6, risco 1) |
| C | Abrir o código do protocolo e do verificador e oferecer recompensa por falha. Complementa A; não substitui |

### D5. Primeiro uso real e trava de demonstração

**Recomendação:** todo escritório começa em **modo demonstração** (a faixa "Ambiente demonstrativo" do desenho, o
seletor "Atuando como", "Gerar 30 votos de teste"). "Eleição real" é liberada pelo Master por escritório, **só
depois de D4-A concluída**. Primeiro piloto: votação institucional sem efeito eleitoral (assembleia ou associação),
com observadores. Eleição oficial da OAB fica fora até convênio e credenciamento (seção 5). Falta ao Samuel dizer
**qual é o primeiro cliente piloto**, porque o cenário dele define a ordem das fases (condomínio puxa a mesa e a
sessão permanente; associação não).

### D6. Identificação do eleitor e e-mail

O desenho tem os dois acessos (código por e-mail com revisão posterior, e PIN com análise prévia): **os dois
entram**. O que decidir:

1. **Provedor de e-mail do Voto.** Recomendado: provedor transacional próprio para o Voto (Amazon SES, Postmark ou
   Resend, por exemplo), fora da cota da Hostinger, que hoje serve todo o BlueJus com 2.400 envios em 24 h.
   Alternativa: até contratar, limitar o acesso por código a eleições pequenas e reservar uma fatia da cota.
2. **Biometria:** fora do primeiro produto (o desenho não compara rosto; uma pessoa confere documento e selfie).
   Prova de vida e duplo fator só para a OAB.
3. **Prazo de guarda das fotos** de documento e selfie: recomendado apagar automaticamente depois do prazo de
   contestação da eleição, com o número como configuração no Master (sugestão inicial: 90 dias após o encerramento).

### D7. Quem libera e quem opera (Coordenador pode decidir pela recomendação)

- **Master:** liga e desliga o Voto no catálogo (nasce desligado) e **libera por escritório** (novo, no molde da IA);
  libera também "eleição real" (D5). O papel "Gestor Eleitoral Master" do desenho é o Master do sistema, só leitura:
  Estatísticas, Painel e Auditoria agregados.
- **No escritório:** a permissão `modules.voto.view` dá acesso à Gestão. Os 8 papéis do desenho são **por votação ou
  evento**, numa tabela própria, designados por nome e e-mail (como no desenho), sem mexer em `TenantRole`.
- **Quem não é usuário do BlueJus** (guardião externo, fiscal de chapa, conselheiro da entidade): entra por link com
  código enviado ao e-mail designado, o mesmo mecanismo do eleitor. Recomendado, porque o desenho designa por
  e-mail e guardião de fora do escritório é o que dá sentido à divisão da chave.

### D8. Correções do desenho e do protótipo

Itens que mudam o que a tela faz (desvio do desenho, pedem o sim do Samuel), com a recomendação:

| # | Defeito no desenho | Recomendação |
|---|---|---|
| 1 | Terminal presencial: "Concluir" não encerra a sessão do eleitor, e a cabine pula o PIN quando há sessão; a próxima pessoa vota como a anterior | "Concluir" encerra a sessão e volta a "Localize seu nome" |
| 2 | Três mensagens mandam o eleitor a "Transparência", mas a aba se chama "Resultados" | Trocar o texto por "Resultados" |
| 3 | A aba "Cadastro para votar" não tem caminho: o aviso "Faça sua habilitação..." não leva a lugar nenhum | O aviso ganha o botão que abre o cadastro |
| 4 | A declaração do art. 299 só aparece no acesso por código | Mostrar também no cadastro por PIN |
| 5 | Bloqueio do PIN no 5º erro sem desbloqueio ("Procure a comissão" sem ação) | Botão "Desbloquear PIN" na tela Eleitores, para a Validação (função nova, fora do desenho) |
| 6 | Guardiões fixos em 5 com mínimo de 3, seja qual for a equipe | Número de guardiões e mínimo vêm da equipe designada (padrão 3 de 5) |
| 7 | Indicadores do Painel somam todas as votações, ignorando a votação escolhida | Respeitar a votação escolhida |
| 8 | "Simular votação" aparece até em votação encerrada ou apurada | Esconder depois de encerrada |
| 9 | Seletor "Atuando como", "Gerar 30 votos de teste" e os avisos de demonstração | Só no modo demonstração (D5); fora dele, o papel vem da designação |

Itens que seguem a convenção do sistema (o desenho é omisso ou é limitação do protótipo; não pedem pergunta):
links do menu para as telas reais; QR Code gerado no servidor; leitura da planilha pelo nome da coluna (o
protótipo lê numa ordem diferente da do modelo); "Adicionar cargo" e "Editar cargos" mantidos como no desenho.

**Cabeçalho (decidido em 09/11/2026):** as telas da equipe logada usam a moldura do sistema (`base.html.twig`, menu
lateral `_sidebar.html.twig`), como em `agenda-1a.md` e `meu-escritorio.md`. O cabeçalho azul de 64 px e o menu no
topo do desenho não entram; a tela do Voto é o conteúdo (faixa de demonstração, abas e o resto) e o link "Voto"
entra no menu existente. O mesmo vale para Ponto (16) e Assinatura (17). O portal do eleitor é público e não tem
moldura do sistema: usa o cabeçalho branco do próprio desenho, como as páginas públicas do Push e do Cálculo têm
layout próprio.

---

## 4. Correções obrigatórias no protocolo (segurança, invisíveis na tela)

Achados da leitura do código (detalhe e prova: anexo de protocolo, seção 17). Entram nas fases C e E sem pergunta,
porque não mudam a tela e o protótipo como está não garante o que promete:

1. **Assinatura cega e Shamir escritos à mão** (hash de domínio próprio, sem conferência do CRT, aritmética sem
   tempo constante): trocar por RFC 9474 e biblioteca de Shamir auditada (D3-A).
2. **Partes dos guardiões juntas no servidor:** a parte sai do sistema no navegador do guardião (arquivo cifrado
   com senha dele) e só volta na cerimônia de apuração; o servidor guarda o compromisso `compD`.
3. **Pseudônimo e hashes sem segredo** (`SHA-256('PART|eid|idH')`; CPF cai por força bruta): HMAC com chave
   secreta do domínio Identidade, guardada fora do banco.
4. **Registro pendente junta pseudônimo, token e assinatura:** no servidor nunca; o token fica só no aparelho do
   eleitor (o ESPEC §16 já pede).
5. **Correlação por tempo e IP:** sem `access_log` do nginx nem log de aplicação com IP nas rotas de autorizar e
   depositar; depósito em lotes com atraso aleatório; trilha sem hora exata de identificação ligada ao voto.
6. **Cédula sem vínculo com o token:** incluir token e peso nos dados autenticados do AES-GCM (hoje só o id da
   eleição), para uma cédula não ser copiada para outro token.
7. **Enchimento de urna:** publicar a lista de participação por faixa e o número de autorizações; o verificador
   compara cédulas com autorizações.
8. **Verificador incompleto e pacote autorreferente:** conferir também decisões (vencedor, quórum), peso dos aptos,
   hash da lista e zerésima; publicar `cfgHash`, `genesis` e cabeça da cadeia fora do sistema (carimbo do tempo ou
   publicação externa), senão um pacote refeito inteiro passa.
9. **Hash da configuração parcial:** incluir `limNome`, `identTipo`, `consultaModo`, `limSessao`, `geo`; mudanças
   depois da abertura (`convocacao`, `limite`) entram na trilha encadeada.
10. **Comparecimento conta autorizações, não cédulas,** e o boletim mostra contagem e não peso em votação com peso:
    corrigir no boletim.
11. **Incidentes fora da cadeia:** entram na trilha encadeada.
12. **Prova de voto:** o pacote público traz a chave `d` e o eleitor tem o BJV; com os dois, o eleitor consegue
    provar em quem votou. É o mesmo limite de sistemas como o Helios (voto remoto não impede coação), mas vira item
    da revisão externa (D4) e do aviso ao cliente.

---

## 5. O que depende de terceiros, de lei ou de regulamento

### 5.1 Terceiros e contratação

| Dependência | Para quê | Bloqueia | Quem |
|---|---|---|---|
| Endereço `voto.bluejus.com.br` (DNS, certificado, bloco do nginx, container) | D1-B | Fase B em produção | Samuel, na VPS |
| Provedor de e-mail transacional | Código de acesso e comprovante sem gastar a cota da Hostinger | Acesso por código em produção | Samuel contrata (D6) |
| Revisão criptográfica independente | Protocolo e código das fases C e E | Primeiro uso real | Samuel contrata (D4) |
| Teste de intrusão independente | Endereço do Voto | Primeiro uso real | Samuel contrata (D4) |
| Teste de carga com relatório de terceiro | Afirmação comercial de capacidade (ESPEC §7) | Venda acima do teto | Samuel contrata |
| Carimbo do tempo (RFC 3161, ICP-Brasil) ou publicação externa | Ancorar `cfgHash`, `genesis` e cabeça da cadeia | Opcional no piloto; necessário depois | Samuel contrata |
| Certificado ICP-Brasil, gov.br, prova de vida, HSM, nuvem com proteção contra DDoS | Exigências do Edital da OAB e de escala | Só eleição da OAB ou grande | Fora do primeiro produto |
| Base oficial da OAB (CNA) e lista de aptos da Comissão | Aptidão oficial | Só eleição da OAB | Convênio |

### 5.2 Lei (não trava a implementação; vai para o pacote jurídico)

Fonte: ESPEC_VOTO.md do Farlei; os textos legais **não foram conferidos nesta etapa**.

- **LGPD:** documento, selfie (dado biométrico se usado para identificar), localização e lista nominal: base legal,
  relatório de impacto, encarregado, prazo de guarda. O consentimento do desenho diz "inclusive dado biométrico".
- **Código Penal, art. 299:** declaração no acesso por código (texto do desenho).
- **Condomínio:** Código Civil, arts. 1.352 a 1.354-A (Lei 14.309/2022): assembleia eletrônica se a convenção não
  vedar; sessão permanente (art. 1.353, §§ 1º a 3º). Conferir a redação atual e a convenção de cada cliente.
- **Recibo com o voto:** STF, ADI 5889; o desenho já não emite (só o comprovante de participação).
- **TSE:** Res. 23.673/2021, alterada pela 23.728/2024, é referência voluntária. O BlueJus nunca pode afirmar
  certificação do TSE nem equivalência à urna eletrônica.
- **Cartório:** eficácia do registro do relatório consolidado a confirmar com o cartório.

### 5.3 Regulamento eleitoral da OAB (só para eleição oficial)

- **Provimento 222/2023:** art. 10 (chapa completa, nome até 30 caracteres, ordem de registro), art. 25
  (modalidade on-line a critério do Presidente do Conselho Seccional, ad referendum da Diretoria; cédula própria de
  subseção), art. 26 (senha pessoal ou certificado, aptos e adimplentes, lista pela Comissão, auditoria contratada),
  art. 27 (fiscais das chapas).
- **Edital 01/2024-CFOAB** (se repetido): duplo fator (4.1.6), comprovante com data, hora e IP (4.1.9), sigilo,
  integridade e logs com proteção criptográfica, criptografia ponta a ponta com certificado ICP-Brasil (4.1.8 a
  4.1.12 e 4.1.21), atestado de 400.000 votos em 8 horas (5.1.1). Retificações I e II ainda não lidas.
- **Regulamento Geral, art. 134, § 6º:** vigência a conferir.
- **Conflito de interesses** se alguém ligado ao BlueJus integrar o Conselho Seccional (ESPEC §12.3).

---

## 6. Etapas em PRs pequenos e estimativa

Cada PR leva o arquivo em `mudancas/`, testes (unitário do UseCase e funcional do controller) e a conferência ✓/✗
contra o anexo de telas. Dias de uma sessão, já com testes; somar cerca de 20% para revisão e correções.

### Fase A: fundação, sem voto (risco MÉDIO)

| PR | Branch | Entrega | Dias | Migration |
|---|---|---|---|---|
| A1 | `voto-base` | Recurso `voto` no catálogo (nasce desligado), liberação por escritório, permissão `modules.voto.view`, item no menu lateral, tela dentro da moldura do sistema com a faixa de demonstração e as abas vazias | 2 | M1 |
| A2 | `voto-infra` | Schema `voto`, conexão do Voto, container `voto`, bloco do nginx com limites, `camera=(self)` e sem `access_log` nas rotas da urna (arquivos no repositório; subir na VPS é do Samuel) | 3 | M2 |
| A3 | `voto-eleicao` | Eleição, evento, cargo, candidatura; 9 cenários; assistente de 7 etapas com rascunho; validações; publicar com hash canônico e código de conferência; modal "Conhecer candidaturas" | 5 | M3 |
| A4 | `voto-equipe` | Perfis: equipe padrão e por votação, 8 papéis, "Designar", "Aplicar cargos da categoria", guardiões, acesso por link para quem não tem conta | 3 | M4 |
| A5 | `voto-aptos` | Lista de aptos: XLSX/CSV, modelo, relatório, hash, bloqueio na abertura, ocorrências e Avaliações do Conselho | 4 | M5 |

### Fase B: o eleitor (risco ALTO)

| PR | Branch | Entrega | Dias | Migration |
|---|---|---|---|---|
| B1 | `voto-portal` | Rotas públicas, "Localize seu nome", consulta de situação, limites por IP, sessão do eleitor; Painel com os 6 passos; modal Link e QR Code, cartaz PNG, "Testar acesso", terminal presencial | 4 | (sem) |
| B2 | `voto-codigo` | Acesso por código por e-mail (6 números, 10 min, 5 tentativas, 5 pedidos por hora), fila `voto` | 3 | M6 |
| B3 | `voto-identificacao` | Documento, selfie, consentimento, art. 299, localização; análise de qualidade da foto no servidor; tela Eleitores (Validação); prazo de guarda das fotos | 5 | M7 |
| B4 | `voto-pin` | "Cadastro para votar", PIN com bloqueio no 5º erro e desbloqueio pela Validação | 2 | (sem, cabe em M7) |

### Fase C: voto e urna (risco ALTO, re-revisão em cada PR)

| PR | Branch | Entrega | Dias | Migration |
|---|---|---|---|---|
| C1 | `voto-abertura` | Cerimônia de abertura: chaves de assinatura por peso (cifradas em repouso), chave da eleição gerada no navegador e partes para os guardiões, zerésima, `genesis`; abertura e encerramento pelo agendador; encerrar apaga as chaves de assinatura | 5 | M8 |
| C2 | `voto-autorizador` | Assinatura cega RFC 9474, pseudônimo com HMAC, faixa horária, voto único por restrição no banco | 4 | (cabe em M8) |
| C3 | `voto-urna` | Depósito: verificação da assinatura, token único, tamanho fixo, AES-GCM com token no AAD, cadeia de hash, códigos BJV e BJC, idempotência, lotes com atraso | 4 | M9 |
| C4 | `voto-cabine` | A urna inteira do desenho: telas, teclado de tela com braille e teclado físico, 7 sons, animações, `prefers-reduced-motion`, simulação, branco e nulo, próxima votação, erro, faixa "VOTO ABERTO" | 5 | (sem) |
| C5 | `voto-comprovante` | Comprovante de participação em imagem e PDF, e-mail, 2ª via com faixa, conferência de BJV e BJC | 2 | (cabe em M9) |

### Fase D: condução (risco BAIXO; só se o piloto precisar de mesa)

| PR | Branch | Entrega | Dias | Migration |
|---|---|---|---|---|
| D1 | `voto-mesa` | Convocações, abrir por minutos ou sem prazo, prazo, encerrar com motivo, sessão permanente (60 e 90 dias), troca de voto, ata parcial | 4 | (campos já em M3) |
| D2 | `voto-acompanhar` | Janela ao vivo, lista nominal, documento de auditoria, sigilo aberto (após e em tempo real) | 3 | (cabe em M10) |

### Fase E: apuração e transparência (risco ALTO)

| PR | Branch | Entrega | Dias | Migration |
|---|---|---|---|---|
| E1 | `voto-apuracao` | Guardiões apresentam a parte no navegador, conferência do compromisso e da urna, abertura das cédulas, totalização com peso, regras e quórum, empate | 4 | M10 |
| E2 | `voto-resultados` | Aba Resultados, 3 boletins em PDF (80 mm), pacote público, verificador independente (página estática e script), "Verificar urna" | 5 | (sem) |
| E3 | `voto-registros` | Relatório consolidado, relação de votantes, trilha encadeada com "Verificar" e "Exportar", incidentes, Estatísticas do Gestor Master e CSV | 4 | (cabe em M10) |
| E4 | `voto-testes` | Aba Testes (só demonstração), "Gerar 30 votos de teste", os 97 testes do protótipo portados para PHPUnit e para o navegador | 3 | (sem) |

### Soma e ordem

- **Total:** A 17 + B 14 + C 20 + D 7 + E 16 = **74 dias**; com revisão e correções, **cerca de 90**.
- **Caminho mínimo para um piloto sem mesa:** A1 a A5, B1, B2 ou B4, B3, C1 a C5, E1, E2 (cerca de 60 dias).
- **Paralelo:** depois de A3, a parte de tela (A4, A5, B1, C4, D) pode correr numa sessão e a parte de protocolo
  (C1 a C3, E1) noutra; a regra "uma migration por vez" põe M4 a M10 em fila.
- **Fora da conta:** revisão externa, teste de intrusão, teste de carga, provedor de e-mail e subida na VPS.

---

## 7. Migrations

Todas no schema `voto` (D1-B), sem alterar tabela existente e sem operação destrutiva; cada uma com `down`. Os
números são dados pelo Coordenador. Tabelas com `tenant_id`; nenhuma entidade do Voto implementa `Auditavel`.

| # | PR | Conteúdo |
|---|---|---|
| M1 | A1 | Dados: linha `voto` desligada em `recurso_estado`; permissão `modules.voto.view` concedida aos perfis de sistema (molde de `Version20261030110000`); tabela de liberação por escritório |
| M2 | A2 | `CREATE SCHEMA voto` e permissões. Se D2 adotar usuário de banco próprio, criar o usuário é passo manual do Samuel na VPS (a migration roda com o usuário dono) |
| M3 | A3 | `voto.evento`, `voto.eleicao` (configuração, estado, `cfg_hash`, campos da mesa e da sessão permanente), `voto.cargo`, `voto.candidatura` |
| M4 | A4 | `voto.membro_da_equipe` (papel, escopo por evento ou votação, nome, e-mail, `user_id` opcional) |
| M5 | A5 | `voto.lista_de_aptos` (hash, bloqueio), `voto.apto` (identificação com HMAC, peso, grupo, situação), `voto.ocorrencia` |
| M6 | B2 | `voto.codigo_de_acesso` (hash do código, validade, tentativas) |
| M7 | B3, B4 | `voto.eleitor` (registro de identidade, situação da análise, PIN com hash, bloqueio), `voto.foto_do_eleitor` (chave do arquivo, nota de qualidade, prazo de guarda) |
| M8 | C1, C2 | `voto.chave_de_assinatura` (por peso, privada cifrada, apagada no encerramento), `voto.cerimonia` (pública da eleição, compromisso, `genesis`, zerésima), `voto.participacao` (pseudônimo, faixa; único por eleição) |
| M9 | C3, C5 | `voto.cedula` (urna: sequência, token, assinatura, cifra, elo da cadeia; **sem ligação com eleitor nem participação**), `voto.comprovante` (BJC) |
| M10 | E1, E3, D2 | `voto.resultado`, `voto.boletim`, `voto.trilha` (encadeada), `voto.incidente`, `voto.voto_nominal` (só sigilo aberto) |

---

## 8. Limites que o produto declara

Escritos na tela e no material comercial, até a revisão externa dizer o contrário:

- Não é certificado por órgão eleitoral, nem equivalente à urna eletrônica.
- Voto remoto não impede que alguém observe ou pressione o eleitor (coação).
- O aparelho do eleitor, se comprometido, pode alterar o voto antes de cifrar (o desafio de cédula fica para depois).
- A identidade é conferida por pessoa, sem comparação automática de rosto.
- Capacidade comprovada só até o teto configurado no Master.

## 9. Pendências para o pacote jurídico

Para o Coordenador anotar em `bluejus-coordenacao/pacote-juridico.md` (não travam a implementação): base legal e
relatório de impacto para documento, selfie e localização; prazo de guarda das fotos; texto do consentimento
("inclusive dado biométrico"); texto da declaração do art. 299; publicação da relação de votantes; eficácia do
registro em cartório; redação atual dos arts. 1.352 a 1.354-A do Código Civil; termos de uso do Voto para a entidade
e para o eleitor.
