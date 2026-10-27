# Spec: Termo de Voluntariado (Trabalho Externo, Banco de talentos)

Desenho: `docs/design/redesign-25-10-26/06 - Trabalho Externo -25.10.26.dc.html`: modal `tmTermo` (l.330 a 352), JS `termoHTML` (l.943 a 970), `termoNovo` (l.1127), `enviarTermo` (l.1134), menu do currículo (l.1093), botão do detalhe (l.324 e 1129), "Meu currículo" (l.230 e 1123), filtro e KPI (l.1088 e 1220).
Risco: **ALTO** na prática (dado pessoal novo e aceite com valor probatório). Migration reservada: `Version20261027153200`.
**O PR não vai a produção antes da revisão do advogado** (decisão do dono, 27/10). O texto do modelo sai LITERAL do desenho; o rodapé do próprio modelo manda revisar com o responsável jurídico. Nenhuma palavra do texto jurídico é alterada por nós, nem o travessão: se o texto do desenho tiver travessão, reescrever só a pontuação e registrar na conferência.

## Fluxo
1. **Gerar** (só quem gere o banco: Master, ou Equipe com a opção talentos): menu da linha do currículo ("Gerar termo de voluntariado") e rodapé do detalhe ("Gerar termo..." ou "Novo termo..." se já houver), só se o currículo tiver voluntariado diferente de "não". Abre o modal com os campos pré-preenchidos (`termoNovo`); nada é gravado ainda (rascunho de tela).
2. **Enviar para aceite**: valida os 7 obrigatórios do desenho (nome e CNPJ/CPF do escritório, nome e CPF do voluntário, data de início, prazo em meses, foro; mensagem do desenho) e grava o termo com status `enviado`. Currículo `novo` passa a `analise` (como o desenho). Toast do desenho.
3. **Aceite do voluntário** ("Meu currículo", dono do currículo): vê o botão/chip de termo para aceitar, abre o termo somente leitura e aceita com o checkbox "Li e aceito..." (texto do desenho; sem marcar, erro do desenho). O servidor grava aceito em, usuário, IP, user-agent e hash.
4. **Ler/baixar**: Word (`.doc` em HTML), Imprimir/PDF. O HTML do termo é montado **no servidor**, num único serviço, a partir do snapshot gravado; a prévia (iframe), o Word e a impressão usam o mesmo HTML.
5. Termo enviado é **imutável**. "Novo termo" cria outro registro copiando o último (id e status zerados). O estado do currículo é o do termo mais recente.

## Estados e textos (do desenho)
Rascunho (não persistido): "Rascunho · revise os dados e envie para aceite" (`#0a6fa0`). Enviado: "Aguardando aceite do(a) voluntário(a) desde {data}" (`#9a5b00`). Aceito: "Aceito eletronicamente em {data hora}" (`#1e7b45`). Botão no detalhe: "Termo de voluntariado aceito em {data}" (verde) ou "Termo enviado em {data} · aguardando aceite" (âmbar). Faixa informativa do modelo, campos, rótulos, placeholders, toasts e a prévia: literais do desenho.

## Pré-preenchimento (`termoNovo`)
Escritório: Tenant (nome, CNPJ, endereço montado, cidade/UF; foro = cidade). E-mail e telefone do tenant não existem: vêm em branco (linha para preencher). Representante: usuário logado (nome, OAB). Voluntário: do currículo (nome, OAB, instituição, semestre, e-mail, cidade/UF); CPF e RG sempre em branco (o currículo não os guarda). Defaults do desenho: atividades, 20 h/semana, modalidade do currículo ou "regime híbrido", início hoje, 6 meses, aviso 10 dias, ressarcimento "não". Se houver termo anterior do currículo, copia dele.

## Dado
Tabela `trabalho_externo_termo_voluntariado`: id, tenant_id (multi-tenant), curriculo_id (FK, `ON DELETE CASCADE`: excluído o currículo, o termo sai junto, e o servidor registra que isso aconteceu na auditoria sem dados pessoais), codigo (`TV` + sequência legível, único por tenant), status (`enviado` | `aceito`), campos (jsonb: o snapshot de tudo que o modal edita, incluindo CPF e RG), versao_modelo (`1.0`), criado_por_id, enviado_em, aceito_em (timestamp do servidor), aceito_por_id, aceite_ip, aceite_user_agent, hash_aceite (sha256 do HTML renderizado no aceite). Índice (tenant_id, curriculo_id, enviado_em).

## Dado pessoal (CPF, RG)
- Só existem dentro do snapshot do termo. Nunca em lista do banco, filtro, KPI, CSV, mensagem, aviso, log ou **auditoria**: o campo `campos` fica fora do diff do `AuditLogSubscriber` (gravar só "termo enviado/aceito" com código e currículo).
- Na tela só o interno que gere e o próprio voluntário veem o termo completo. Outros veem o status.
- Mesma proteção em repouso dos demais CPFs do sistema (coluna comum; o banco já é cifrado em disco). Cifragem em coluna fica como pendência do Master se o dono quiser.
- Exclusão da conta/escritório: o termo vai na purga do escritório (`PurgarEscritorioUseCase`) e na exclusão do usuário voluntário (dono do currículo).

## Permissões
Gerar/enviar: interno que gere (como o resto do banco). Ver o termo de um currículo: quem gere; o voluntário só o próprio. Aceitar: só o dono do currículo, só se `enviado`. Outro tenant/usuário: 404. CSRF nas escritas.

## Integrações
Filtro "Termo" (Sem termo, aguardando, aceito), KPI VOLUNTÁRIOS "N aceitos · M aguardando" e coluna Termo do CSV passam a ler o termo mais recente (hoje fixos em `sem`: `ApresentadorDeCurriculo`, `FiltroDeCurriculos`, `ExportarCurriculosCsvUseCase`, `PainelDoBancoDeTalentos`). Aviso ao voluntário quando o termo é enviado (chip "Você tem um termo de voluntariado para aceitar", l.1123) e aviso ao escritório quando aceita (o desenho diz "O escritório foi avisado"): aviso de andamento/sino do sistema, sem CPF no texto.

## Fora desta entrega
PDF gerado no servidor (o desenho usa Word em HTML e Imprimir). Assinatura ICP-Brasil. Revisão do texto pelo advogado (bloqueia a produção, não o código).

## Testes
Unit: serviço do HTML (campos em branco viram linha, cláusula 4.2 conforme ressarcimento, "extenso" até 99, escape de HTML), UseCases (gerar rascunho, enviar com obrigatórios, aceitar com hash/IP, imutável, não aceitar duas vezes). Functional: rotas e permissões (outro tenant 404, voluntário alheio 404, CSRF), filtro/KPI/CSV, CPF/RG ausentes de lista, CSV e auditoria, exclusão do currículo e purga.
