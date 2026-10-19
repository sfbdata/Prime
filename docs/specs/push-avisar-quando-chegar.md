# Painel do Push, parte B: "Avisar quando chegar" (P4b)

Empilhada sobre `push-painel-monitoramento` (#82): usa a coluna do painel (`.ps-push-coluna`).
Desenho: `docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html` L2629-2636 (cartão) e
L5447 (`avisos`). No protótipo as três chaves só mudam estado de tela; aqui ganham efeito real. Risco BAIXO.

## Decisões

- D1 (coordenador, 19/10): as chaves valem POR PASTA. "Responsável da pasta" e "Por e-mail" nascem LIGADAS, como
  no desenho.
- D2 (a confirmar com o dono): "Análise da BlueJus IA" nasce DESLIGADA (ligada geraria chamada de IA em toda
  publicação nova de todas as pastas no dia do deploy). O padrão de cada chave fica numa única classe
  (`App\Pasta\Service\PadroesDoAvisoDoPush`), com um comentário "a confirmar"; trocar é mudar uma constante.
- D3 (coordenador): todo e-mail leva "Não quero mais receber estes avisos". O descadastro é um serviço e uma tabela
  reaproveitáveis (`App\Shared\...\Descadastro`, tabela `email_descadastro`: tenant, email normalizado, assunto
  (`push_aviso_equipe`, futuro `push_cliente`…), em, origem), não presos ao Push; a frente "Comercial e e-mails"
  vai consolidar.
- D4 (coordenador): remetente `MAILER_FROM` com o nome do escritório no nome de exibição e "Responder para" o
  responsável da pasta (aqui o destinatário já é o responsável: Responder para fica o próprio remetente padrão).

## 1. Modelo

`pasta_aviso_push` (`App\Pasta\Entity\AvisoDoPushDaPasta`, TenantAware, Auditavel): pasta (FK cascade, UNIQUE),
avisar_responsavel, por_email, analise_ia (bool), atualizado_por (SET NULL), atualizado_em. Sem linha = padrões de
`PadroesDoAvisoDoPush`. `email_descadastro` como em D3 (UNIQUE tenant + email + assunto).

## 2. Tela (L2629-2636)

Cartão "AVISAR QUANDO CHEGAR" abaixo do cartão de monitoramento: três linhas com o rótulo exato e o switch
(`role=switch`, mesmo visual do switch do monitoramento): "Responsável da pasta", "Por e-mail", "Análise da BlueJus
IA". `POST /pasta/{id}/push/avisos` (CSRF, EDIT na pasta; VIEW vê desabilitado com dica; lápide desabilitado)
grava a chave. "Por e-mail" só faz sentido com "Responsável da pasta" ligado: com ele desligado, o switch de e-mail
fica desabilitado (dica "Ligue o aviso ao responsável para receber por e-mail."). "Análise da BlueJus IA" só aparece
habilitada se a plataforma tiver IA e o escritório a tiver ligada (mesma regra da aba); senão desabilitada com o
motivo real.

## 3. Efeito

Ao fim de cada captação (`SincronizarPublicacoesDjenUseCase`, que roda no comando diário e no "Buscar agora"), para
cada publicação NOVA vinculada a um processo, para cada pasta não excluída desse processo:
- **Responsável da pasta** ligado e pasta com responsável ativo no escritório: um aviso no sino (tipo novo
  `push_publicacao_pasta`, mensagem "Nova movimentação na Pasta {nº}: {tipo} de {dd/mm/aaaa}" ou "N novas
  movimentações na Pasta {nº}", URL `/pasta/{id}#push`). Um aviso por pasta por captação (agrupa).
- **Por e-mail** ligado (e responsável ligado, e o e-mail do responsável não descadastrado): e-mail para o
  responsável com o mesmo conteúdo, a lista das movimentações (tipo, data, órgão, resumo de até 220 caracteres, nunca
  o teor inteiro), botão "Abrir a pasta" e o link "Não quero mais receber estes avisos" (URL assinada, sem login,
  que grava o descadastro e mostra uma página simples de confirmação). Template `templates/email/push_aviso_equipe`.
- **Análise da BlueJus IA** ligada: solicita o resumo do Push da pasta (o mesmo `SolicitarResumoDoPushUseCase` do
  botão "Resumir com IA"), uma vez por pasta por captação, se a IA estiver disponível; o resultado chega pelo fluxo
  que já existe.
- O aviso geral do módulo (`NotificadorPublicacoesDjen`, "N novas publicações capturadas no DJEN") continua igual.
- Falha de e-mail não derruba a captação (registra no log e segue).
- Respeita a aba Push oculta (PR #68) quando estiver no master: não avisar quem tem o Push oculto naquela pasta.
  Neste PR fica como pendência registrada (o #68 ainda não está no master).

## 4. Testes mínimos

Unit: padrões, decisão de quem avisar (responsável inativo, pasta excluída, e-mail descadastrado, IA indisponível),
agrupamento por pasta. Functional: rota das chaves (CSRF, EDIT, VIEW 403, outro tenant 404), cartão com os textos e
estados, captação com dublê do DJEN gera aviso no sino e e-mail (MailerAssertions) e solicita a IA só quando ligada,
link de descadastro (assinatura válida grava; inválida 404; idempotente), e-mail descadastrado não recebe.
