# Rascunho: o que a Política de Privacidade precisa ganhar com o Contribua

> Para o dono e o advogado revisarem. **Nada aqui está publicado**: o texto vigente continua em
> `app/templates/legal/_politica_privacidade_texto.html.twig`. Texto jurídico é decisão de vocês;
> este rascunho só aponta onde a política fica desatualizada quando o Contribua entrar e propõe redação.

## 0. A empresa: BlueJus para tudo (decisão do dono, 19/10/2026)

"Para tudo é a BlueJus." A **BlueJus Gestão e Tecnologia LTDA, CNPJ 66.803.163/0001-10**, é a mantenedora do
sistema, a controladora dos dados, quem recebe os pagamentos e quem emite as notas. A PRIME GESTÃO
EMPRESARIAL LTDA (CNPJ 62.916.987/0001-46) **sai da política**.

Proposta para o capítulo 1.1 (tabela "Identificação do responsável"):

| Item | Hoje | Passa a ser |
|---|---|---|
| Mantenedora | PRIME GESTÃO EMPRESARIAL LTDA | BLUEJUS GESTÃO E TECNOLOGIA LTDA |
| CNPJ | 62.916.987/0001-46 | 66.803.163/0001-10 |
| Cidade | Brasília, Distrito Federal | Brasília, Distrito Federal (QND 14, Lote 7, Sala 11-A, Taguatinga Norte, CEP 72.120-140) |
| Encarregado, e-mail, telefone | (sem mudança) | (sem mudança, salvo decisão do advogado) |

Onde a Prime aparece hoje (busca em 19/10 no código e nos documentos): só na política, em dois lugares, que
mudam juntos:
- `app/templates/legal/_politica_privacidade_texto.html.twig`, linhas 79 e 80 (tabela do capítulo 1.1);
- `docs/legal/politica-de-privacidade.docx` (a fonte do texto), na mesma tabela.

Não citam a Prime: os Termos de Uso (texto e PDF, que não nomeiam razão social), o "Quem somos" do
Contribua (já é BlueJus), o rodapé do login ("© BlueJus") e os e-mails (remetente `[BlueJus]`).

### Como a troca entra no sistema (nada disso foi feito; espera o OK final do dono e do advogado)

- A política **não** entra no fluxo de aceite (`app/src/Legal/PoliticaPrivacidadeVigente.php`): trocar o
  texto **não** obriga ninguém a reaceitar e não trava nenhum usuário no deploy. Quem força reaceite é só a
  versão dos Termos de Uso (`app/src/Termo/TermoVigente.php` + `TermoAceiteListener`), que não muda aqui.
- A troca é um PR próprio, pequeno, **separado do Contribua**, aberto só depois do OK final. No mesmo commit:
  1. o texto novo em `_politica_privacidade_texto.html.twig` (este rascunho, revisado pelo advogado);
  2. `PoliticaPrivacidadeVigente::VERSAO` de `1.0.2026` para `1.1.2026` e `DATA_PUBLICACAO` para o dia do
     deploy;
  3. uma linha nova no Anexo III ("Controle de versões") com a data e o resumo da mudança;
  4. o `docs/legal/politica-de-privacidade.docx` atualizado, para a fonte continuar batendo com o texto.
- No deploy: a página `/politica-de-privacidade` e o PDF passam a mostrar a nova versão e a nova data; quem
  já usa o sistema segue usando, sem tela nova.
- A LGPD manda informar o titular, com destaque, quando muda a identificação do controlador (art. 8º, § 6º,
  combinado com o art. 9º, III). O caminho proposto é uma Novidade e um e-mail no dia da troca, não um
  reaceite. Forma e texto do aviso: decisão do advogado.

## 1. Anexo I (relação de suboperadores): linhas novas

Hoje o anexo tem 3 linhas (hospedagem, e-mail e IA). Entram, conforme cada integração for ligada:

| Suboperador | Finalidade | Dados | Local de processamento |
|---|---|---|---|
| Cora Sociedade de Crédito, Financiamento e Investimento S.A. (banco 403) | Recebimento de contribuições voluntárias por PIX e boleto, e confirmação do pagamento | número da conta no BlueJus, valor, dados do pagador informados no aplicativo do banco | Brasil |
| Gateway de cartão: Mercado Pago, Stripe, Asaas e/ou Pagar.me (um por linha, só os que forem ativados) | Assinatura mensal de espaço: cobrança recorrente no cartão, cancelamento e nota fiscal (quando o gateway emite) | nome do titular, CPF/CNPJ, e-mail, plano, valor, bandeira e 4 últimos dígitos (o número do cartão vai direto ao gateway) | Brasil (Mercado Pago, Asaas, Pagar.me); **Estados Unidos e outros (Stripe)** |
| Banco Inter, Banco do Brasil ou outro banco por API PIX (se ativados) | Mesma finalidade da Cora | mesmos dados | Brasil |

Se a Stripe for ativada, o capítulo 14 (transferência internacional) passa a ter um caso real e precisa
citar o mecanismo usado (cláusulas contratuais padrão).

## 2. Capítulo 5 (dados tratados como controladora)

A categoria "De faturamento" já existe e cobre plano, histórico de pagamentos e dados de cobrança.
Proposta de acréscimo ao fim do capítulo:

> Os dados do cartão de crédito (número, validade e código de segurança) são digitados em ambiente do
> próprio processador de pagamento e transformados em código de uso único no navegador do titular.
> A BLUEJUS não recebe, não armazena e não tem acesso a esses dados; guarda apenas a bandeira, os quatro
> últimos dígitos e a identificação da assinatura junto ao processador.

## 3. Capítulo 6 (finalidades e bases legais): linhas novas

| Finalidade | Base legal proposta |
|---|---|
| Cobrar a assinatura de espaço, renovar, cancelar e confirmar pagamentos | Execução de contrato, artigo 7º, inciso V |
| Registrar contribuições voluntárias e conceder os benefícios de apoiador (selo, preferência no Trabalho Externo, acesso antecipado) | Execução de contrato, artigo 7º, inciso V |
| Receber sugestões de melhoria e responder a quem enviou | Execução de contrato, artigo 7º, inciso V |
| Registrar, de forma anônima, o motivo de ainda não contribuir | Legítimo interesse, artigo 7º, inciso IX (a resposta não identifica a pessoa, só a conta) |

## 3.1 Rastreio de abertura e de clique nos e-mails comerciais (frente "Comercial e e-mails", 5d)

O sistema passa a poder medir se os e-mails de campanha e de dicas foram abertos e quais links foram
clicados. Tecnicamente, isso só liga quando a política citar o rastreio: a constante
`PoliticaPrivacidadeVigente::CITA_RASTREIO_DE_EMAIL` (hoje `false`) passa a `true` no mesmo PR que publicar
este texto. Proposta de redação:

> Nos e-mails de campanha e de dicas, a BLUEJUS pode usar uma imagem invisível de abertura (pixel) e links
> rastreados, para saber se a mensagem foi aberta e quais conteúdos interessaram. Os e-mails operacionais
> (segurança, cobrança, avisos do sistema) não são rastreados. O titular pode se descadastrar das
> comunicações comerciais a qualquer momento, pelo link no rodapé de cada mensagem.

- Capítulo 6: a finalidade "medir o alcance das comunicações comerciais", com base no legítimo interesse
  (art. 7º, IX) para clientes ativos e no consentimento (art. 7º, I) para quem ainda não é cliente, como as
  linhas de comunicação comercial que a política já tem.
- Capítulo 17 / Anexo II (prazos de retenção): os registros de envio, abertura e clique guardados por
  **24 meses** (proposta da 5d, ainda com o dono, P4).
- Anexo II: a categoria "Registros de comunicação comercial (envio, abertura, clique)".

## 4. Capítulo 17 (retenção)

> Os registros de pagamento, assinatura e nota fiscal são mantidos pelo prazo exigido pela legislação
> tributária e contábil, mesmo após o cancelamento da assinatura ou o encerramento da conta.

(Prazo a confirmar pelo advogado; o usual é 5 anos para documentos fiscais.)

## 5. Capítulo 12 (publicidade): sem mudança

O Contribua não exibe anúncio nem compartilha dado com anunciante.

## 6. Um ponto do desenho que depende da política

"Nada é apagado, mesmo se cancelar" (garantia do desenho) é compatível com o capítulo 17 enquanto a
conta existir. Se o escritório pedir a exclusão da conta, vale a purga do capítulo 17, não a garantia. A
tela já diz isso no contexto de cancelar a assinatura, que é diferente de excluir a conta.
