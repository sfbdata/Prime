# SPEC canônica — Acompanhamento de cobranças por unidade

> **Status:** especificação funcional canônica para o próximo redesenho do domínio `App\Cobranca`.
>
> **Finalidade:** servir como fonte de verdade para o Claude Code produzir um `PLAN` técnico antes
> de qualquer implementação.
>
> **Precedência:** quando houver conflito sobre objeto, caso, responsáveis, importação, acordos,
> contatos, pagamentos, prescrição, telas ou indicadores, este documento prevalece sobre as SPECs
> anteriores do domínio de cobrança. Os documentos e mockups anteriores não devem ser apagados:
> permanecem como histórico e referências de alternativas já exploradas.

## 1. Objetivo

Criar uma experiência simples para o escritório acompanhar cobranças de unidades inadimplentes,
reduzindo ao mínimo o tempo e a quantidade de cliques exigidos do cobrador.

Ao abrir a área de cobrança, o usuário precisa compreender rapidamente:

- quais unidades estão sendo cobradas;
- quem são os responsáveis atuais por cada unidade;
- quantas cobranças estão vencidas;
- quais valores e competências estão em aberto;
- qual foi a última ação ou observação;
- há quanto tempo a última ação foi realizada;
- qual cobrança prescreverá primeiro;
- se existe acordo, em qual etapa operacional ele está;
- se existem boletos ou documentos pendentes;
- o que cada cobrador realizou no período selecionado.

O sistema é uma ferramenta de **registro, acompanhamento, cálculo e priorização da cobrança**. A
contabilidade continua responsável por seus próprios lançamentos oficiais e pela geração dos
boletos.

## 2. Problema atual

Sem um fluxo integrado, o trabalho ocorre assim:

1. o cobrador baixa a planilha de inadimplentes do sistema da contabilidade;
2. consulta ou mantém dados cadastrais em bases paralelas;
3. entra em contato, na maioria das vezes por WhatsApp;
4. registra tentativas, conversas e resultados em uma planilha de acompanhamento;
5. quando negocia um acordo, elabora o documento e o envia para assinatura;
6. depois de assinado, envia o documento à contabilidade;
7. a contabilidade gera os boletos;
8. os boletos são encaminhados ao devedor;
9. novos relatórios precisam ser comparados para descobrir alterações e possíveis pagamentos.

Isso fragmenta informações, consome tempo operacional e dificulta à gerência saber o que está
acontecendo em cada unidade.

## 3. Princípios do produto

1. **A unidade é o centro da experiência.**
2. **Registrar deve custar menos tempo que executar a cobrança.**
3. **Uma ação explícita deve gerar todos os eventos derivados necessários, sem duplicar cliques.**
4. **O sistema registra o trabalho; não substitui a decisão do cobrador.**
5. **Atas orientam pessoas, mas o sistema não interpreta nem impõe seus limites.**
6. **A contabilidade é a fonte externa dos relatórios e boletos.**
7. **Importação nunca presume quitação apenas porque uma linha desapareceu.**
8. **Correções manuais relevantes têm histórico e não são sobrescritas silenciosamente.**
9. **Informações incompletas geram pendência visível, não bloqueio desnecessário.**
10. **Isolamento entre tenants é inegociável em toda leitura, mutação, arquivo e importação.**

## 4. Vocabulário oficial

### 4.1 Cliente / condomínio

O condomínio é uma entidade `Cliente` do sistema, pois contratou o escritório para realizar as
cobranças.

As atas pertencem ao cliente/condomínio.

### 4.2 Carteira

Organiza a operação de cobrança de um cliente/condomínio e mantém suas configurações gerais.

### 4.3 Objeto de cobrança

É o elemento permanente ao qual as obrigações pertencem. No cenário principal, é uma unidade do
condomínio.

O objeto:

- continua existindo quando não há inadimplência;
- recebe competências de forma contínua;
- mantém pessoas vinculadas e o responsável principal atual;
- concentra obrigações, parcelas, acordos, pagamentos, contatos, observações, documentos,
  acompanhamento e judicialização;
- é a raiz da página operacional `Objeto show`.

### 4.4 Obrigação

É um valor exigível associado ao objeto, normalmente uma competência condominial ou uma parcela de
acordo.

Uma obrigação possui, no mínimo:

- origem;
- identificador externo, quando disponível;
- classe de conta;
- competência;
- vencimento;
- principal;
- juros;
- multa;
- correção;
- honorários;
- total atualizado;
- situação;
- prescrição estimada;
- referência ao acordo, quando for parcela.

### 4.5 Pessoa vinculada

Pessoa física ou jurídica relacionada ao objeto. Uma pessoa pode possuir:

- nome ou razão social;
- CPF ou CNPJ;
- RG e órgão expedidor;
- data de nascimento;
- estado civil;
- profissão;
- observação cadastral;
- vários telefones;
- vários e-mails;
- vários endereços.

Cada telefone, e-mail e endereço deve poder indicar qual é o principal e sua origem.

### 4.6 Responsável atual

Um objeto pode possuir vários responsáveis atuais, mas apenas um deles é o **principal**.

O responsável principal:

- vem inicialmente da importação cadastral;
- pode ser corrigido manualmente;
- aparece em destaque na lista e na página do objeto;
- é uma informação para orientar o cobrador.

Alterar o responsável principal não executa uma operação financeira de transferência. O sistema não
decide, valida nem distribui juridicamente as obrigações entre pessoas. Ele apenas apresenta quem é
o responsável principal informado atualmente.

### 4.7 Acordo

É a negociação em que competências ou outras obrigações originais são substituídas por novas
parcelas, com novos valores e vencimentos.

Um acordo somente está **fechado** quando seu documento estiver assinado.

### 4.8 Contato

É uma interação ou tentativa de interação com o devedor por telefone, WhatsApp, e-mail, SMS ou
outro canal.

### 4.9 Observação

É um texto livre e opcional associado a uma ação ou registrado isoladamente para documentar o que
foi conversado ou percebido.

### 4.10 Acompanhamento agendado

É uma ação futura marcada pelo cobrador. Diferentemente da simples contagem desde a última ação,
possui data de vencimento própria.

## 5. Decisão estrutural: remover `CasoCobranca`

### 5.1 Decisão de negócio

Não existe um ciclo independente denominado caso de cobrança:

- a unidade é permanente;
- as obrigações são contínuas;
- novas competências pertencem à mesma unidade;
- acordo, pagamento e judicialização não criam uma nova identidade de cobrança;
- todo o histórico deve ser compreendido a partir do objeto.

Portanto, `CasoCobranca` deixa de fazer parte do modelo-alvo.

### 5.2 Modelo-alvo

As relações atualmente ancoradas no caso passam a pertencer diretamente ao objeto, incluindo:

- obrigações;
- pagamentos e alocações;
- acordos e parcelas;
- liquidações, caso continuem necessárias;
- próxima ação ou acompanhamento;
- contatos;
- observações;
- eventos históricos;
- documentos e seções;
- alertas e pendências;
- pasta judicial.

`ObjetoCobranca` torna-se o agregado financeiro e operacional da cobrança.

### 5.3 Condição atual

Não há usuários utilizando o módulo de cobrança. A decisão não precisa preservar um fluxo antigo em
produção nem manter `CasoCobranca` como âncora invisível.

Mesmo assim, o `PLAN` deve:

- inventariar todas as FKs e serviços ligados a `CasoCobranca`;
- definir a sequência segura de alteração do esquema;
- impedir perda dos dados de desenvolvimento já existentes;
- atualizar testes, fixtures e importadores;
- eliminar código morto e terminologia residual;
- não manter uma camada de compatibilidade sem necessidade real.

### 5.4 Terminologia de interface

A palavra “caso” não deve aparecer na interface. Os termos visíveis são:

- unidade;
- objeto;
- cobrança;
- competência;
- parcela;
- acordo;
- responsável;
- contato;
- acompanhamento;
- judicialização.

## 6. Fonte dos dados

### 6.1 Relatórios disponíveis

Os arquivos reais analisados foram:

1. `Inadimplências detalhadas`;
2. `Dados cadastrais dos condôminos`;
3. `Acordos detalhados em andamento`;
4. `Receitas detalhadas por unidade/cliente`.

### 6.2 Responsabilidade de cada relatório

| Relatório | Responsabilidade no sistema |
|---|---|
| Inadimplências detalhadas | Posição aberta, competências, vencimentos e valores atualizados |
| Dados cadastrais | Pessoas vinculadas, papel cadastral e contatos conhecidos |
| Acordos detalhados | Obrigações originais substituídas, parcelas, liquidação e situação contábil do acordo |
| Receitas detalhadas | Confirmação de recebimentos e seus componentes financeiros |

### 6.3 Dados confirmados em inadimplências detalhadas

O arquivo possui:

- Unidade;
- Sacado;
- NN;
- Classe de conta;
- Competência;
- Vencimento;
- Atraso;
- Valor;
- Juros;
- Multa;
- Correção;
- Honorários;
- Total;
- Informações do acordo;
- Recebimento.

O mesmo relatório baixado em dias diferentes mantém os títulos e atualiza atraso e encargos.

### 6.4 Dados confirmados no cadastro

O arquivo possui:

- Unidade;
- Nome/Nome fantasia;
- tipo cadastral do sacado, como `Proprietário` ou `Pessoa relacionada`;
- CPF/CNPJ;
- Fração ideal;
- Endereço;
- E-mail;
- Telefone.

O arquivo pode trazer:

- mais de uma pessoa para a mesma unidade;
- mais de um telefone na mesma célula;
- dados ausentes;
- placeholders inválidos, como `null () null`;
- pessoas repetidas em mais de uma unidade.

### 6.5 Dados confirmados em acordos

Cada acordo é apresentado em uma aba própria e informa:

- número do acordo;
- unidade;
- data-base;
- sacado;
- valor total das contas originais;
- valor final acordado;
- data de criação;
- situação;
- relação das contas originais;
- NN, classe, competência, vencimento e valor original;
- parcelas geradas;
- NN da parcela;
- número da parcela;
- competência;
- vencimento;
- liquidação;
- valor acordado;
- valor liquidado.

### 6.6 Dados confirmados em receitas

O relatório possui:

- Unidade;
- Sacado;
- NN;
- Classe de conta;
- Competência;
- Vencimento;
- Recebimento;
- Valor;
- Valor recebido;
- Informações do acordo.

As classes podem representar separadamente:

- principal;
- juros;
- multa;
- desconto;
- honorários;
- outros componentes.

Existem linhas repetidas e vários componentes para o mesmo NN. O importador não pode somar ou criar
pagamentos linha a linha sem normalização e deduplicação.

## 7. Identidade e normalização da importação

### 7.1 Escopo obrigatório da chave

Nenhuma chave externa pode ser considerada global. Toda correspondência deve incluir:

- tenant;
- cliente/condomínio ou carteira;
- origem/adaptador;
- identificador externo normalizado.

### 7.2 Identificação do objeto

Como os relatórios analisados não apresentam um ID imutável da unidade, o adaptador deve:

1. normalizar a identificação textual;
2. procurar correspondência dentro da carteira correta;
3. apresentar ambiguidades no preview;
4. nunca unir automaticamente unidades de carteiras ou tenants diferentes;
5. permitir que o usuário corrija a correspondência antes de confirmar.

O `PLAN` deve verificar se o sistema contábil oferece algum código de unidade em outro formato de
exportação antes de assumir definitivamente o texto como chave.

### 7.3 Identificação das obrigações

Para a fonte analisada, `NN` é o principal candidato a identificador do título. O adaptador deve
separar:

- identidade do título/obrigação;
- componentes financeiros apresentados em linhas diferentes;
- duplicidades exatas do arquivo;
- totais e rodapés que não são registros.

Uma estratégia concreta de chave e agrupamento deve ser provada por testes com os quatro relatórios
antes da implementação definitiva.

### 7.4 Importação idempotente

Reimportar o mesmo arquivo:

- não duplica objetos;
- não duplica pessoas;
- não duplica obrigações;
- não duplica acordos;
- não duplica parcelas;
- não duplica pagamentos;
- não duplica eventos históricos.

Cada execução mantém registro de:

- arquivo;
- tipo de relatório;
- data e hora;
- usuário;
- tenant;
- carteira;
- quantidade criada;
- quantidade atualizada;
- quantidade ignorada;
- divergências;
- erros.

## 8. Processo de importação e reconciliação

### 8.1 Fluxo geral

Toda importação segue:

1. selecionar carteira/condomínio;
2. enviar arquivo;
3. detectar o tipo de relatório;
4. validar cabeçalhos;
5. normalizar e deduplicar;
6. exibir preview;
7. mostrar criações, atualizações, ausências e divergências;
8. confirmar;
9. executar em transação;
10. apresentar resultado auditável.

Nenhuma escrita ocorre durante o preview.

### 8.2 Inadimplência

Na confirmação:

- cria objetos ainda inexistentes;
- cria ou atualiza obrigações pelo identificador externo;
- atualiza valores e encargos informados;
- preserva capacidade de cálculo diário do próprio sistema;
- registra o momento em que a obrigação foi vista pela última vez no relatório;
- atualiza o sacado informado sem apagar vínculos manuais.

### 8.3 Obrigação ausente no relatório seguinte

Uma obrigação que existia e não aparece no novo relatório:

- não é marcada como paga automaticamente;
- recebe a situação `Pagamento a verificar`;
- permanece visível como pendência de reconciliação;
- pode ser confirmada por relatório de receitas;
- pode ser resolvida manualmente, com origem e usuário registrados;
- pode voltar a aparecer em importação futura sem ser duplicada.

### 8.4 Receitas e confirmação de pagamento

O sistema controla pagamentos, mas o cobrador não é obrigado a digitá-los rotineiramente.

O pagamento pode ser confirmado por:

- importação do relatório de receitas;
- confirmação manual.

Toda confirmação registra:

- origem;
- usuário, quando manual;
- data de recebimento;
- valor;
- componentes;
- obrigação ou parcela afetada;
- identificador externo;
- importação de origem.

O adaptador deve agrupar e deduplicar componentes do mesmo NN antes de registrar o recebimento.

### 8.5 Dados cadastrais

Na importação:

- pessoas são comparadas dentro do tenant;
- CPF/CNPJ normalizado é a chave preferencial;
- ausência de documento exige correspondência assistida;
- telefones, e-mails e endereços novos podem ser acrescentados;
- placeholders inválidos são descartados;
- mais de uma pessoa pode ficar vinculada à mesma unidade;
- o proprietário importado pode ser sugerido como responsável principal.

### 8.6 Precedência entre importação e correção manual

Dados corrigidos manualmente não são sobrescritos silenciosamente.

Quando houver conflito:

- o novo valor importado é preservado como candidato ou divergência;
- o valor manual continua principal;
- o usuário pode aceitar o importado;
- a decisão fica no histórico.

Novos contatos importados podem ser acrescentados sem apagar os existentes.

## 9. Prescrição

### 9.1 Regra funcional

A prescrição estimada é calculada individualmente:

- competência original: vencimento + 5 anos;
- parcela de acordo assinado: vencimento da parcela + 5 anos.

### 9.2 Próxima prescrição do objeto

É a menor prescrição estimada entre as obrigações abertas e atualmente exigíveis do objeto.

### 9.3 Efeito do acordo

Quando o acordo é assinado:

- as obrigações originais são substituídas;
- deixam de participar da próxima prescrição enquanto o acordo estiver válido;
- cada nova parcela passa a ter sua própria prescrição estimada.

Quando o acordo é cancelado:

- as parcelas são canceladas;
- as obrigações originais voltam a valer;
- voltam a participar do cálculo da próxima prescrição;
- seus encargos voltam a ser calculados até a data atual.

### 9.4 Correção manual

A data estimada pode ser corrigida manualmente, mediante:

- justificativa obrigatória;
- usuário;
- data e hora;
- preservação do valor calculado anteriormente.

O sistema apresenta uma estimativa operacional e não substitui análise jurídica.

### 9.5 Prioridade visual

Prescrição é o alerta mais grave da área de cobrança. A lista e a página do objeto devem destacar
proximidade de prescrição sem impedir ações.

## 10. Contatos e observações

### 10.1 Ação permanente

`Registrar contato` permanece disponível em qualquer etapa:

- antes da negociação;
- aguardando assinatura;
- depois do acordo assinado;
- aguardando contabilidade;
- depois do envio dos boletos;
- durante acompanhamento;
- depois da judicialização.

### 10.2 Campos mínimos

- data e hora, preenchidas com o momento atual e editáveis;
- canal;
- resultado;
- observação/relato opcional.

Resultados mínimos:

- não atendido;
- atendido;
- caixa postal;
- número errado;
- pediu retorno;
- informou outro número;
- outro.

### 10.3 Ação operacional que também é contato

Ações como:

- acordo enviado para assinatura;
- boleto enviado ao devedor;
- documento enviado;

já representam contato. Uma única confirmação deve gerar:

1. o evento operacional específico;
2. a atividade de contato correspondente;
3. os dados analíticos necessários.

Não se exige um segundo clique em `Registrar contato`.

O formulário compacto da ação pode solicitar:

- canal;
- observação opcional.

### 10.4 Observação opcional

Toda ação pode receber observação opcional, porque a ação já é autoexplicativa.

Exemplo:

- evento: `Acordo enviado para assinatura`;
- canal: WhatsApp;
- observação: `Pediu para analisar com a esposa e responder amanhã`.

Também existe `Adicionar observação` para textos que não correspondem a uma mudança operacional.

### 10.5 Última observação

A lista e o cabeçalho do objeto exibem o último texto humano relevante. Eventos técnicos sem relato
não devem substituir silenciosamente essa informação.

## 11. Tempo desde a última ação

Não existem SLAs automáticos para contato, acordo ou contabilidade.

O sistema apenas informa:

- `Último contato há 10 dias`;
- `Acordo enviado há 2 dias`;
- `Enviado à contabilidade há 3 horas`;
- `Nenhuma ação registrada há 120 dias`.

Essa duração pode ser usada para filtro e ordenação, mas:

- não cria vencimento;
- não marca automaticamente como atrasado;
- não executa ação;
- não muda estágio;
- não bloqueia o usuário.

Possuem vencimento real somente:

- obrigação;
- parcela;
- acompanhamento agendado;
- prescrição estimada.

## 12. Fluxo de acordo

### 12.1 Princípios

- o sistema não precisa elaborar o documento;
- o documento pode ser elaborado fora do sistema;
- geração automática de documento fica para uma etapa futura, quando existirem modelos;
- não existe upload obrigatório de minuta;
- acordo fechado significa documento assinado;
- só pode existir um documento assinado para o mesmo acordo;
- a unidade pode possuir vários acordos diferentes ao longo do tempo.

### 12.2 Estados e ação principal

| Estado | Ação principal |
|---|---|
| Sem proposta em andamento | `Acordo enviado para assinatura` |
| Aguardando assinatura | `Registrar acordo assinado` |
| Acordo assinado | `Registrar envio à contabilidade` |
| Enviado à contabilidade | `Registrar boletos recebidos` |
| Boletos recebidos | `Registrar envio ao devedor` |
| Boletos enviados | `Agendar acompanhamento` |

### 12.3 Acordo enviado para assinatura

A ação registra:

- devedor/pessoa destinatária;
- obrigações incluídas;
- condições negociadas;
- entrada, se houver;
- quantidade, valores e vencimentos das parcelas propostas;
- descontos e encargos;
- canal;
- data e hora;
- observação opcional.

Nesse momento:

- as competências originais continuam exigíveis;
- nenhuma substituição financeira ocorre;
- a proposta fica aguardando assinatura.

### 12.4 Substituição da proposta

Enquanto não houver assinatura:

- a proposta pode permanecer aguardando indefinidamente;
- pode ser substituída por uma nova;
- a anterior fica registrada como substituída;
- as competências originais continuam válidas;
- não se cria acordo financeiro ativo duplicado.

### 12.5 Registro do acordo assinado

Exige:

- documento assinado;
- data da assinatura;
- confirmação das condições finais.

Ao confirmar:

- o acordo fica fechado/assinado;
- as obrigações originais ficam substituídas;
- as parcelas passam a ser exigíveis nos novos vencimentos;
- a prescrição passa a ser calculada por parcela;
- documento, usuário e evento ficam registrados.

### 12.6 Envio à contabilidade e boletos

O fluxo registra:

1. acordo enviado à contabilidade;
2. boletos recebidos;
3. boletos enviados ao devedor.

Arquivos de boletos podem ser anexados no recebimento ou posteriormente.

Se o usuário continuar sem anexá-los:

- a ação não é bloqueada;
- surge a pendência `Boleto sem documento anexado`;
- anexar posteriormente resolve a pendência.

### 12.7 Cancelamento

O acordo assinado pode ser cancelado.

Regras:

- motivo obrigatório;
- alerta quando houver pagamento conhecido;
- o sistema não bloqueia rigidamente a decisão, pois a confirmação contábil pode estar defasada;
- parcelas do acordo são canceladas;
- obrigações originais voltam a valer;
- encargos são recalculados até a data atual;
- acordo e documento assinado permanecem no histórico como cancelados.

### 12.8 Inadimplência do acordo

Não existe entidade ou ação de `romper acordo`.

Quando uma parcela vence sem pagamento conhecido:

- o acordo aparece inadimplente por estado derivado;
- o cobrador pode registrar contatos;
- pode agendar acompanhamento;
- pode judicializar o objeto.

## 13. Solicitação de boleto sem acordo

Quando o devedor deseja pagar uma única cobrança sem parcelamento:

| Estado | Ação principal |
|---|---|
| Solicitação identificada | `Solicitar boleto sem acordo` |
| Solicitado à contabilidade | `Registrar boleto recebido` |
| Boleto recebido | `Registrar envio ao devedor` |
| Boleto enviado | `Agendar acompanhamento` |

O sistema registra o fluxo, mas não valida regras do condomínio sobre quando deveria existir acordo.

O boleto pode ser anexado imediatamente ou depois. Se estiver ausente, cria-se pendência sem bloquear
o registro.

## 14. Judicialização

`Judicializar` é uma ação do objeto.

Ao acioná-la, o sistema oferece:

1. vincular uma pasta existente do mesmo tenant;
2. criar uma nova pasta e vinculá-la.

Regras:

- nunca vincular pasta de outro tenant;
- respeitar autorização aplicável à área de cobrança;
- registrar usuário, data e pasta no histórico;
- judicialização não cria outro objeto nem outro ciclo de cobrança;
- obrigações, contatos, acordos e pagamentos permanecem no mesmo objeto;
- contatos e acompanhamentos continuam possíveis depois da judicialização.

## 15. Atas

As atas pertencem ao cliente/condomínio.

A interface apresenta:

- lista ordenada pela data da ata, da mais recente para a mais antiga;
- título ou descrição;
- data;
- pré-visualização;
- download.

O sistema:

- não define qual ata está vigente;
- não interpreta regras;
- não limita desconto, entrada ou parcelamento;
- não exige recadastro das regras;
- serve apenas como consulta documental.

## 16. Lista principal de unidades

### 16.1 Objetivo

Ser a fila de trabalho do cobrador e a visão rápida da gerência.

### 16.2 Colunas mínimas

| Coluna | Conteúdo |
|---|---|
| Unidade | Identificação do objeto |
| Responsável principal | Pessoa principal atual |
| Cobranças vencidas | Quantidade de obrigações vencidas e composição resumida |
| Valor atualizado | Total atualmente exigível |
| Última observação | Último texto humano relevante |
| Última ação | Tipo e tempo transcorrido |
| Próxima prescrição | Menor data entre obrigações abertas |
| Situação | Etapa operacional ou pendência principal |
| Cobrador | Usuário responsável pelo condomínio, quando aplicável |

Exemplo de composição:

> `2 cobranças vencidas: 1 parcela de acordo e 1 competência`

### 16.3 Interação

- clicar na linha abre a página `Objeto show`;
- ao retornar, preservar filtros, ordenação, página e posição sempre que possível;
- não criar modal nem painel lateral como fluxo principal.

### 16.4 Filtros

- condomínio/carteira;
- cobrador;
- situação;
- tipo de cobrança;
- com acordo aguardando assinatura;
- aguardando contabilidade;
- boleto pendente;
- sem documento de boleto;
- com acompanhamento vencido;
- prescrição mais próxima;
- tempo desde a última ação;
- busca por unidade, nome, CPF/CNPJ, telefone ou e-mail.

## 17. Página `Objeto show`

### 17.1 Objetivo

Concentrar o atendimento completo da unidade sem criar uma segunda tela denominada caso.

### 17.2 Hierarquia

A prioridade da página é permitir agir. Ordem conceitual:

1. identificação da unidade, situação e valores;
2. alerta de prescrição;
3. ação principal dinâmica;
4. ações permanentes;
5. última observação;
6. responsável principal e contatos rápidos;
7. cobranças vencidas;
8. acordo ou solicitação de boleto em andamento;
9. linha do tempo;
10. pessoas vinculadas;
11. documentos;
12. atas do condomínio;
13. calculadora e detalhamento financeiro.

O `PLAN` de UX pode agrupar visualmente os blocos, desde que preserve essa prioridade.

### 17.3 Ação principal dinâmica

Mostra a próxima ação do fluxo que está efetivamente em andamento:

- acordo;
- boleto sem acordo;
- acompanhamento.

Não obriga o cobrador a seguir uma máquina rígida. Ações de correção e exceção permanecem
disponíveis em menu secundário.

### 17.4 Ações permanentes

No mínimo:

- Registrar contato;
- Adicionar observação;
- Atualizar pessoa;
- Vincular pessoa;
- Alterar responsável principal;
- Abrir calculadora;
- Corrigir encargos;
- Anexar documento;
- Judicializar.

### 17.5 Pessoas e contato rápido

O responsável principal aparece em destaque com:

- telefones;
- e-mails;
- endereços;
- atalhos visuais para canal;
- indicação de dado principal;
- acesso à qualificação completa.

Todas as pessoas vinculadas permanecem acessíveis na mesma página.

### 17.6 Dívida em aberto

Mostrar primeiro:

- quantidade de cobranças vencidas;
- valor atualizado;
- competência mais antiga;
- próxima prescrição;
- parcelas vencidas;
- próxima parcela;
- resumo do acordo.

Não expandir por padrão dezenas de parcelas futuras.

Para acordo longo, mostrar:

- situação;
- `X de Y parcelas pagas`;
- parcelas vencidas;
- próxima parcela;
- botão para expandir o cronograma completo.

### 17.7 Calculadora e encargos

Devem continuar existindo:

- calculadora de parcelamento;
- cálculo dos encargos pelos dias;
- edição de juros;
- edição de multa;
- edição de correção;
- edição de honorários;
- visualização da composição do valor.

O redesenho é de experiência, não de remoção dessas capacidades.

### 17.8 Linha do tempo

Ordem decrescente, com rolagem própria ou paginação incremental.

Deve reunir:

- contatos;
- observações;
- propostas enviadas;
- acordo assinado;
- envio à contabilidade;
- recebimento e envio de boletos;
- acompanhamentos;
- importações relevantes;
- pagamentos;
- divergências resolvidas;
- cancelamentos;
- judicialização;
- alterações cadastrais relevantes.

Eventos automáticos devem ser visualmente distintos de textos humanos.

## 18. Cobrador responsável

Na prática, um cobrador costuma ser responsável por um condomínio, mas outro cobrador pode ajudar.

Portanto:

- a responsabilidade principal pode ser definida por condomínio/carteira;
- qualquer usuário com acesso à cobrança do tenant pode atuar nas unidades;
- toda ação registra quem a realizou;
- indicadores distinguem responsável principal de executor da ação;
- não é necessário transferir formalmente a unidade para permitir ajuda.

## 19. Central gerencial

### 19.1 Filtros

- condomínio/carteira;
- cobrador;
- período.

Períodos rápidos:

- dia;
- semana;
- mês;
- trimestre;
- semestre;
- ano;
- personalizado.

O período é filtro, não um tipo de relatório separado.

### 19.2 Visão do que ocorre nas unidades

A gerência precisa enxergar:

- unidades sem ação recente;
- unidades com prescrição próxima;
- acordos aguardando assinatura;
- acordos assinados aguardando contabilidade;
- boletos aguardados;
- boletos sem documento;
- boletos aguardando envio ao devedor;
- acordos com parcela vencida;
- acompanhamentos vencidos;
- objetos judicializados;
- pagamentos a verificar;
- últimas observações relevantes.

### 19.3 Atividades contabilizadas separadamente

- tentativas sem resposta;
- contatos com resposta;
- unidades contatadas;
- acordos enviados;
- acordos assinados;
- boletos sem acordo solicitados;
- boletos recebidos;
- boletos enviados;
- acompanhamentos agendados;
- pagamentos confirmados;
- objetos judicializados.

Uma ação que também representa contato alimenta as duas dimensões sem exigir dois registros.

### 19.4 Indicadores de conversão

Não usar somente `acordos ÷ total de contatos`, pois uma unidade pode exigir muitas tentativas.

Apresentar, no mínimo:

- acordos assinados ÷ unidades contatadas;
- acordos assinados ÷ contatos com resposta;
- unidades que pagaram ÷ unidades contatadas;
- valor confirmado como recebido no período;
- valor negociado em acordos assinados.

`Acordo fechado` nos indicadores significa acordo assinado.

## 20. Alertas e pendências

Não transformar toda obrigação vencida em alerta genérico, pois isso gera uma lista sem prioridade.

Alertas e pendências devem representar algo acionável, como:

- prescrição próxima;
- acompanhamento vencido;
- pagamento a verificar;
- boleto sem arquivo;
- acordo aguardando contabilidade;
- boleto recebido ainda não enviado;
- parcela de acordo vencida;
- divergência de importação;
- dado cadastral conflitante.

Cada item deve apontar para o objeto e para a ação capaz de resolvê-lo.

## 21. Documentos

Documentos pertencem ao objeto ou a uma entidade específica dele.

Categorias mínimas:

- acordo assinado;
- boleto;
- ata;
- documento cadastral;
- documento de negociação;
- notificação;
- outro.

Regras:

- acordo assinado: exatamente um por acordo;
- boletos: zero ou vários por acordo/solicitação, com pendência enquanto ausentes;
- ata: ligada ao cliente/condomínio;
- arquivos sempre tenant-scoped;
- preview e download autorizados;
- histórico de upload e remoção;
- nenhum caminho previsível público deve permitir acesso direto cross-tenant.

## 22. Situações derivadas

Evitar armazenar estados que possam ser determinados com segurança:

- obrigação vencida: vencimento anterior a hoje e sem pagamento confirmado;
- parcela de acordo vencida: parcela vencida e sem pagamento confirmado;
- acordo inadimplente: possui ao menos uma parcela vencida;
- próxima prescrição: menor prescrição das obrigações exigíveis;
- sem ação recente: duração calculada desde a última ação;
- pagamento a verificar: obrigação ausente na posição aberta sem confirmação de receita;
- boleto sem documento: fluxo registrado sem arquivo correspondente.

Estados operacionais que representam ação humana devem ser persistidos e historizados.

## 23. Permissões e multi-tenancy

### 23.1 Regra de acesso

Quem possui acesso à área de cobrança pode executar todas as ações da área.

Pode existir uma permissão única, por exemplo `COBRANCA_ACESSAR`, em vez de fragmentar o fluxo em
dezenas de permissões.

### 23.2 Isolamento obrigatório

Toda operação deve validar explicitamente o tenant:

- objetos;
- pessoas;
- vínculos;
- obrigações;
- acordos;
- pagamentos;
- contatos;
- documentos;
- atas;
- importações;
- pastas vinculadas;
- relatórios e indicadores.

Regras:

- busca por ID nunca confia somente na PK;
- arquivos nunca são localizados sem tenant;
- CPF/CNPJ nunca deduplica pessoas entre tenants;
- preview de importação não consulta nem sugere dados de outro tenant;
- pasta judicial precisa pertencer ao mesmo tenant;
- filtros e agregações sempre incluem tenant;
- testes cross-tenant são obrigatórios para leituras, mutações, downloads e importações.

## 24. Auditoria e histórico

Distinguir:

- **histórico operacional**, visível ao cobrador;
- **auditoria técnica**, destinada a rastreabilidade e segurança.

Toda mutação relevante registra:

- tenant;
- objeto;
- entidade afetada;
- tipo da ação;
- usuário;
- data e hora;
- origem manual ou importada;
- valores anteriores e novos quando aplicável;
- observação;
- arquivo ou importação relacionada.

## 25. Fora do escopo desta entrega

- integração em tempo real com API da contabilidade;
- envio direto de WhatsApp ou e-mail pelo sistema;
- assinatura eletrônica dentro do sistema;
- geração automática do documento de acordo;
- interpretação automática de atas;
- bloqueio automático de descontos ou condições;
- decisão jurídica automatizada sobre responsabilidade;
- decisão jurídica definitiva sobre prescrição;
- automação de judicialização;
- obrigação de o cobrador registrar manualmente todos os pagamentos.

## 26. Critérios de aceite funcionais

### 26.1 Lista

- lista unidades de uma carteira sem N+1 perceptível;
- mostra responsável principal;
- mostra quantidade de cobranças vencidas;
- apresenta composição como competência/parcela;
- mostra última observação;
- mostra última ação e tempo transcorrido;
- mostra próxima prescrição;
- abre `Objeto show`;
- filtros não misturam tenants.

### 26.2 Objeto show

- concentra todas as informações da unidade;
- ação principal aparece antes do detalhamento extenso;
- `Registrar contato` permanece disponível em qualquer etapa;
- última observação está evidente;
- pessoas e contatos podem ser atualizados;
- parcelas futuras ficam recolhidas por padrão;
- calculadora e correção de encargos continuam funcionando;
- histórico reúne ações automáticas e observações.

### 26.3 Acordo

- enviar proposta não substitui obrigações;
- substituir proposta preserva a anterior no histórico;
- assinar exige documento;
- assinar substitui obrigações por parcelas;
- só existe um documento assinado por acordo;
- prescrição passa a usar vencimentos das parcelas;
- cancelar restaura obrigações originais;
- parcela vencida deriva acordo inadimplente;
- não existe fluxo obrigatório de rompimento.

### 26.4 Boleto

- permite solicitar boleto sem acordo;
- registra envio à contabilidade, recebimento e envio ao devedor;
- arquivo pode ser anexado depois;
- ausência cria pendência sem bloquear;
- anexar resolve a pendência.

### 26.5 Importação

- preview não escreve;
- reimportação é idempotente;
- duplicidades do XLSX não viram registros duplicados;
- ausência na inadimplência vira `Pagamento a verificar`;
- receita confirma pagamento;
- confirmação manual registra origem;
- conflitos cadastrais não sobrescrevem correção manual;
- todas as chaves incluem tenant e carteira/origem.

### 26.6 Gestão

- filtra por condomínio, cobrador e período;
- oferece dia, semana, mês, trimestre, semestre, ano e personalizado;
- mostra atividades separadas;
- acordo fechado significa assinado;
- ações que também são contato não exigem registro duplicado;
- mostra situação atual das unidades, não apenas contagens.

### 26.7 Judicialização

- permite vincular pasta existente do mesmo tenant;
- permite criar nova pasta;
- mantém o mesmo objeto;
- registra histórico;
- rejeita pasta de outro tenant.

## 27. Requisitos não funcionais

- valores monetários em centavos inteiros;
- datas de negócio com tipo apropriado, sem depender implicitamente do timezone do servidor;
- importações grandes processadas sem timeout ou duplicação;
- queries gerenciais com agregação tenant-scoped e índices avaliados no `PLAN`;
- ações críticas protegidas por CSRF;
- downloads autorizados;
- falhas de importação com rollback;
- logs sem conteúdo sensível desnecessário;
- testes unitários dos UseCases antes das camadas externas;
- testes funcionais;
- testes de repositório;
- testes cross-tenant;
- testes de idempotência e reconciliação;
- testes dos cálculos de prescrição, acordo e cancelamento.

## 28. Orientação para geração do PLAN

O Claude Code deve primeiro ler:

- esta SPEC;
- entidades, repositórios, UseCases, controllers, DTOs, forms, templates e testes atuais de
  `App\Cobranca`;
- migrations atuais do domínio;
- adaptador de importação TopLife;
- integrações com `Cliente`, `Pasta`, documentos, autorização e tenant;
- SPECs anteriores apenas como histórico técnico.

Depois deve produzir um `PLAN` que:

1. confronte o estado atual com o modelo-alvo;
2. liste todas as dependências de `CasoCobranca`;
3. proponha a remoção completa do caso, sem âncora invisível;
4. preserve o que já funciona em calculadora, encargos, pagamentos e documentos;
5. divida o trabalho em fatias verticais testáveis;
6. comece pelos UseCases e testes;
7. trate importação por adaptadores;
8. inclua migração, backfill e limpeza do código morto;
9. inclua revisão de performance e índices;
10. inclua testes cross-tenant por fatia;
11. não implemente nada antes de o PLAN ser revisado e aprovado.

### 28.1 Saída esperada do PLAN

Para cada fatia:

- objetivo;
- comportamento entregue;
- arquivos e camadas afetados;
- alteração de banco;
- UseCases;
- testes;
- riscos;
- critério de conclusão;
- dependências entre fatias.

### 28.2 Perguntas técnicas que o PLAN deve responder

- qual sequência remove `CasoCobranca` com menor retrabalho;
- quais relações passam diretamente para `ObjetoCobranca`;
- quais campos atuais do caso já estão mortos ou duplicados;
- como modelar proposta antes da assinatura sem substituir obrigações;
- como registrar uma ação operacional e sua dimensão de contato uma única vez;
- como normalizar NN e componentes financeiros;
- como reconciliar inadimplência e receitas;
- como representar precedência manual/importada;
- como manter a página rápida com muitas competências, parcelas e eventos;
- como garantir que todos os relatórios gerenciais sejam tenant-safe.

