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
- Se o advogado entender que a troca de controladora pede **aviso** aos usuários (LGPD, art. 9º, § 2º),
  o caminho é uma Novidade e um e-mail, não um reaceite. Isso é decisão dele.

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
