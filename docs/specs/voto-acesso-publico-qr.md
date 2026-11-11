# Voto: acesso público da assembleia e QR Code do edital

Data: 11/11/2026. Risco ALTO (nova entrada pública para eleitor). Frente voto-acesso-publico-qr, base origin/master 11a668048. Prioridade solicitada pelo Samuel antes das mudanças de Pautas.

## Objetivo

O gestor precisa de um link estável da assembleia e de QR Code para imprimir no edital. O participante abre esse endereço e solicita seu acesso individual. O link público não concede voto por si só nem revela unidades, nomes, e-mails ou tokens.

## Referência e escopo

Artefato 3c9fe618-65e4-457f-8d3d-c31bb68901c8, export atual preservado no checkout raiz em docs/design/voto/export-2026-11-11. Usar CSS, componentes e textos do portal e Link e QR Code do desenho; manter links e funções individuais existentes. Portal novo; nenhuma função antiga sai.

Decisão de Samuel: seguir com o código de seis números do desenho. Localização por nome, unidade e bloco; confirmação pelo e-mail já cadastrado. Não há alteração do destinatário pela página pública. Nenhum dado encontrado é mostrado antes da confirmação. Fotos/documentos e identificação forte completa permanecem na etapa posterior já mapeada, fora desta entrega de acesso por e-mail.

## Contratos independentes da decisão

- Assembleia tem codigoPublico aleatório próprio (32 hex), estável e único. Não depende de token de unidade nem do segredo da aplicação. Novas recebem no construtor; existentes recebem por nova migration aditiva, sem alterar migration publicada.
- Rota /voto/v/{codigo}, nome voto_assembleia_portal. Não usar voto_publico_* (reservado ao container separado). Apenas assembleia publicada, escritório ativo e liberado pelo Master fica acessível; chave global voto continua valendo.
- Resolver assembleia pelo código com restauração do filtro de tenant quando chamado fora do listener; toda consulta posterior usa o escritório real da assembleia.
- Respostas públicas sem cache, indexação ou Referer. Não expor lista ou token pessoal. CSRF nos POST. Limites próprios por IP e destinatário no envio, resposta genérica para impedir enumeração.
- QR é gerado pelo servidor com BaconQrCode já instalado, codifica URL absoluta da rota pública, com margem branca para impressão. PNG e SVG gerados em memória, sem armazenamento ou dependência de CDN.
- Download da gestão exige a guarda do módulo e posse da assembleia. Nenhum ID de outro escritório concede download ou revela título. Mostrar link/QR após publicar.

## UseCases

MontarPortalDaAssembleiaUseCase: participante abre código público; resolver assembleia publicada e liberada; retornar DTO público mínimo (título/código); inexistente, rascunho, escritório inativo ou não liberado retorna null e HTTP 404 uniforme.

MontarDivulgacaoDaAssembleiaUseCase: gestor autorizado pede link/QR; buscar assembleia por id E tenant; exigir publicação; montar URL absoluta e DTO de divulgação; recusar outro escritório como inexistente. QR da divulgação nunca usa token individual.

SolicitarCodigoDaAssembleiaUseCase: validar assembleia publicada e liberada, localizar unidade apta pelos dados exatos normalizados e e-mail cadastrado, travar unidade e emitir 6 números aleatórios. Guardar HMAC do código vinculado ao desafio do navegador, validade de 10 minutos, até 5 pedidos por unidade por hora e até 5 tentativas por código. Resposta pública genérica mesmo sem correspondência. Enviar código somente ao endereço persistido, com transporte de teste no desenvolvimento.

ConfirmarCodigoDaAssembleiaUseCase: mesma assembleia/liberação e unidade, trava pessimista, registrar tentativa inclusive formato inválido e persistir recusa sem rollback da contagem. Código expirado/usado/outra sessão recusado. Acerto consome o desafio uma vez e devolve o token atual da cédula individual já existente. Não grava escolha de voto nem altera links antigos. POST com CSRF e limitação por IP; sessão guarda desafio aleatório e localização, nunca o código. Estado de OTP vive na unidade já não auditável e sai na purga existente.

Divulgação: janela Link e QR Code no desenho com link canônico, QR, downloads PNG/SVG para edital e cartaz 1000x1414, testar acesso, terminal e WhatsApp. Não tratar código público/identificador como hash canônico das regras: a configuração verificável permanece etapa posterior. Nenhuma mensagem real durante desenvolvimento.

## Testes e revisão

Antes das demais camadas: unit dos UseCases e functional de HTTP. Cobrir anônimo, sessão de outro escritório, liberação e chave global, rascunho, inatividade, 404 uniforme, CSRF, limitação e ausência de PII/tokens; gestor de outro tenant recusado; link permanece após edição; QR PNG/SVG válido com margem.

Conferir migration up/down em banco isolado e preservação dos códigos existentes. Gates da frente, revisão read-only, correções e re-revisão por risco ALTO antes do PR. Smoke no navegador é do Samuel. Nenhuma alteração de produção ou merge.

## Arquivos compartilhados previstos

security.yaml e framework.yaml, listas de rotas públicas dos listeners de tenant e termo; mapping Assembleia e uma migration nova. Não há outro PR aberto de Voto nem arquivo sobreposto na conferência de 11/11. Há migrations antigas em PRs de Contribua (#152, #123, #94, #93 e #92); nenhuma usa o número desta frente. Samuel deve ordenar as integrações/publicações, mantendo uma migration por vez.

## Conferência do desenho e limites desta entrega

| Região | Estado | Evidência |
|---|---|---|
| Cabeçalho branco, logo e trilho de quatro passos | ✓ estrutural | Layout público existente, entrada.html.twig; teste por filho direto |
| Localizar nome, unidade e bloco; e-mail cadastrado; confirmar seis números | ✓ | Formulários derivados de csTem/vTokTem, fluxo HTTP e correio de teste |
| Dez minutos, cinco erros, cinco pedidos/h, uso único, navegador e reenvio | ✓ | UnidadeDaAssembleia e UseCases; trava no banco e testes |
| Janela Link e QR Code, link canônico, imagem, copiar e testar | ✓ estrutural | divulgacao.html.twig; seletor direto modal > corpo > imagem |
| Cartaz 1000x1414, PNG/SVG do QR para edital | ✓ | Dimensões testadas; posições e cores de baixarQr do artefato |
| Terminal presencial, próximo participante e saída do terminal | ✓ funcional | Estado de sessão, limpeza de identidade, bloqueio do histórico no terminal ativo |
| Fotografias, selfie, PIN e identificação forte completa | Fora desta entrega | Etapas posteriores já mapeadas; aqui confirma-se o e-mail cadastrado |
| Fidelidade visual final em tela/câmera real | Pendente com Samuel | Smoke manual, sem Playwright pelo agente |

Adaptações necessárias ao sistema publicado: identificação usa unidade/bloco do cadastro atual; resposta de pedido genérica evita revelar cadastro; não mostrar código de demonstração; texto de rodapé do cartaz descreve identificação exigida sem rotular dados reais como fictícios; código de conferência divulgado identifica o acesso, sem alegar selo criptográfico das regras. Mantidos os links individuais e o voto mínimo já publicados.

Saída explícita do terminal é necessária porque o estado é compartilhado pelas abas do navegador: Sair do terminal remove suas chaves e restaura o uso normal dos links. O bloqueio do histórico vale enquanto o modo terminal e a sessão estão ativos; os links individuais continuam sendo credenciais próprias do fluxo legado.

## Validação final em 11/11/2026

Suíte completa aprovada com ParaTest em quatro processos: 24.520 testes e 151.280 assertions, em 8min43s, nos bancos isolados votoqrci preparados pelo fluxo oficial do CI. Os 258 testes do Voto, 15 testes de purga, PHPStan nível 5, formatação, lints de container/Twig/YAML e mapeamento Doctrine também passaram. Revisão read-only e re-revisões de risco ALTO aprovadas. Conferência visual, câmera e recebimento real do e-mail pendentes com Samuel.
