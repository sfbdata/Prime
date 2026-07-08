# Especificação Funcional — Gestão de Cobranças

## 1. Objetivo deste documento

Este documento define as regras de negócio e os limites do MVP da feature **Gestão de Cobranças**.

O objetivo é servir como fonte de verdade para o planejamento da implementação. Antes de propor qualquer solução técnica, o ClaudeCode deve analisar o sistema atual, identificar os domínios já existentes que serão integrados e então apresentar um plano de implementação por etapas.

**Não implementar imediatamente. Primeiro analisar o projeto atual e propor o plano.**

A feature deve ser genérica para um SaaS jurídico multi-tenant e não pode ser modelada apenas com base no caso atual de condomínios ou nos relatórios específicos recebidos da contabilidade.

---

## 2. Problema que a feature resolve

Escritórios de advocacia precisam acompanhar cobranças de clientes credores desde o registro da inadimplência até a resolução financeira.

O fluxo pode envolver:

- várias obrigações vencidas;
- atualização de valores e novos prazos de pagamento;
- pagamentos parciais ou totais;
- acordos e parcelamentos;
- mudanças da pessoa que está sendo cobrada;
- tentativas de contato;
- próximas ações e alertas;
- judicialização;
- pagamentos posteriores à judicialização;
- liquidação por dinheiro, bens ou outras formas;
- honorários advocatícios;
- relatórios financeiros e operacionais.

O sistema deve organizar e acompanhar esse trabalho. Ele não deve tomar decisões jurídicas automaticamente.

---

## 3. Princípios gerais

1. O sistema é multi-tenant e nenhum dado pode atravessar escritórios.
2. O domínio deve ser genérico e atender diferentes tipos de cobrança.
3. O cliente do escritório é o credor.
4. O devedor não é cliente do escritório.
5. A dívida pertence ao objeto de cobrança, e não diretamente à pessoa.
6. Decisões sensíveis continuam sendo humanas; o sistema alerta, registra e preserva histórico.
7. Dados financeiros e jurídicos relevantes não devem desaparecer sem rastreabilidade.
8. A feature deve aproveitar os domínios já existentes do sistema quando fizer sentido, sem duplicar funcionalidades.
9. O MVP deve resolver bem o fluxo principal sem tentar criar um motor universal para todo tipo de cobrança existente no Brasil.

---

## 4. Vocabulário oficial

### Cliente / Credor

É o cliente já cadastrado no sistema e representado pelo escritório.

Na feature de cobranças, ele exerce o papel de credor.

Exemplos:

- condomínio;
- empresa;
- locadora;
- imobiliária;
- pessoa física credora.

Não criar outro cadastro separado de credor.

### Carteira de Cobrança

Representa uma operação ou contexto de cobrança de um cliente.

Um mesmo cliente pode possuir mais de uma carteira.

Exemplos:

- cotas condominiais;
- aluguel de salas;
- recuperação de contratos;
- cobrança de veículos.

A carteira concentra configurações e padrões de operação.

### Objeto de Cobrança

É o elemento ao qual a dívida está vinculada.

Exemplos:

- unidade;
- sala;
- veículo;
- imóvel;
- contrato;
- matrícula;
- outro objeto identificável.

A interface pode usar nomenclaturas adequadas ao tipo de carteira, mas o conceito de domínio deve continuar genérico.

### Pessoa

É o cadastro reutilizável de pessoas que participam do domínio de cobranças, como proprietário, inquilino, devedor, fiador ou outro envolvido.

A pessoa:

- pertence sempre a um tenant;
- pode participar de vários objetos e casos dentro do mesmo tenant;
- não se torna Cliente apenas por participar de uma cobrança;
- pode existir mesmo sem vínculo formal com um objeto;
- pode ter CPF ou CNPJ, mas esses documentos não são obrigatórios.

Quando CPF ou CNPJ forem informados, o sistema deve usá-los para ajudar a evitar duplicidades dentro do mesmo tenant. Nunca deve haver deduplicação ou busca de identidade atravessando tenants.

### Pessoa Vinculada

É uma Pessoa que possui ou possuiu relação com o objeto de cobrança.

Exemplos de vínculo:

- proprietário;
- coproprietário;
- inquilino;
- ocupante;
- possuidor;
- representante;
- outro.

Pessoa vinculada não é automaticamente cliente nem automaticamente devedor.

### Caso de Cobrança

Representa um episódio ou processo de cobrança relacionado a um único objeto.

Cada caso mantém seus próprios:

- saldo;
- pessoa cobrada atual;
- obrigações;
- pagamentos e liquidações;
- acordos;
- honorários;
- histórico;
- próximas ações;
- alertas;
- fase extrajudicial ou judicial;
- vínculo com pasta judicial, quando existir.

### Pessoa Cobrada Atual

É exatamente uma pessoa que está sendo cobrada naquele caso.

A escolha é feita manualmente por um gestor autorizado e permanece válida até ser alterada manualmente.

Não existe mais de uma pessoa sendo cobrada simultaneamente no mesmo caso.

### Obrigação

É um valor devido dentro de um caso de cobrança.

Pode representar:

- competência;
- parcela;
- mensalidade;
- taxa;
- aluguel;
- débito específico;
- outra obrigação.

A obrigação possui, no mínimo:

- descrição ou referência;
- valor original;
- vencimento original.

### Acordo

É uma negociação que substitui uma ou várias obrigações do mesmo caso por novas obrigações, normalmente parcelas.

### Liquidação

É qualquer forma reconhecida de redução do saldo da dívida.

Pode acontecer por:

- pagamento em dinheiro;
- bem móvel;
- bem imóvel;
- outro bem ou direito;
- outra forma aceita na negociação.

### Nomenclatura oficial

Usar sempre os termos:

- **Carteira de Cobrança** para a operação do cliente/credor;
- **Objeto de Cobrança** para a unidade, veículo, contrato, imóvel ou equivalente;
- **Caso de Cobrança** para o episódio ou processo de cobrança.

A palavra **cobrança** pode ser usada como nome geral da feature, mas não deve ser usada sozinha quando o significado correto for Caso de Cobrança.

Essa nomenclatura deve ser mantida na documentação, no plano e na implementação para evitar ambiguidade.

---

## 5. Estrutura conceitual

A relação principal é:

- Tenant
- Cliente / Credor
- Carteira de Cobrança
- Objeto de Cobrança
- Caso de Cobrança

O objeto pode possuir vários casos ao longo do tempo.

A carteira define como o sistema lida com casos ativos no mesmo objeto.

---

## 6. Configuração da quantidade de casos ativos

Cada carteira deve escolher um dos modos:

### Modo A — uma cobrança ativa por objeto

Enquanto existir um caso ativo, novas obrigações entram nele.

Depois de encerrado, uma nova inadimplência cria um novo caso.

### Modo B — várias cobranças ativas por objeto

O mesmo objeto pode possuir vários casos independentes ao mesmo tempo.

Ao registrar uma nova pendência, o gestor escolhe:

- adicionar a uma cobrança ativa existente; ou
- criar uma nova cobrança.

### Regras para o modo com várias cobranças

- nenhuma cobrança isolada representa o saldo total do objeto;
- o objeto mostra o saldo consolidado de todas as cobranças ativas;
- cada cobrança mantém seu saldo próprio;
- cada cobrança possui sua própria pessoa cobrada atual;
- pagamentos não atravessam cobranças;
- acordos não atravessam cobranças;
- judicialização pertence à cobrança, não ao objeto;
- o dashboard pode mostrar tanto quantidade de objetos inadimplentes quanto quantidade de cobranças ativas.

---

## 7. Pessoas e vínculos com o objeto

A Pessoa deve existir separadamente do vínculo e deve ser reutilizável dentro do mesmo tenant.

Antes de criar uma nova Pessoa, o sistema deve ajudar a identificar possíveis cadastros já existentes no mesmo tenant, especialmente quando houver CPF ou CNPJ informado.

A ausência de CPF ou CNPJ não impede o cadastro.

O vínculo é uma relação temporal entre uma Pessoa e um Objeto de Cobrança.

O vínculo deve registrar:

- pessoa;
- objeto;
- tipo de vínculo;
- data de início;
- data de encerramento, quando existir;
- motivo do encerramento;
- observação, quando necessário.

### Regra de vigência

A data final não é previamente definida.

O vínculo permanece aberto até ocorrer um evento real de encerramento, como:

- venda;
- substituição;
- saída;
- fim da locação;
- outro motivo registrado.

Uma pessoa anterior não deve ser apagada por mudança de vínculo.

O histórico precisa permanecer disponível.

---

## 8. Pessoa cobrada atual

Cada caso ativo deve possuir exatamente uma pessoa cobrada atual.

### Regras

1. A pessoa cobrada é escolhida manualmente.
2. A escolha permanece válida até alteração manual.
3. Novas competências ou obrigações não exigem nova escolha.
4. Mudança de proprietário, inquilino ou outro vínculo não altera automaticamente a pessoa cobrada.
5. A carteira pode ter uma preferência para sugerir o tipo de vínculo normalmente cobrado.
6. A sugestão nunca substitui a decisão do gestor.
7. A pessoa cobrada pode ser uma pessoa vinculada ao objeto ou uma pessoa relacionada diretamente ao caso, mesmo sem vínculo formal com o objeto.
8. Continua existindo exatamente uma pessoa cobrada atual por caso.
9. Toda alteração da pessoa cobrada deve registrar histórico, motivo, data e usuário responsável.
10. A mudança da pessoa cobrada não altera dívida, pagamentos, acordos ou documentos existentes.

### Mudança relevante de vínculo

Se ocorrer uma alteração relevante no objeto enquanto existir cobrança ativa, o sistema pode gerar uma pendência operacional para revisão.

Exemplo:

- a pessoa cobrada deixou de ser proprietária;
- existe um novo proprietário;
- a cobrança continua direcionada à pessoa anterior até decisão do gestor.

A revisão pode resultar em:

- manter a pessoa atual; ou
- substituir a pessoa cobrada.

Depois da revisão, o mesmo evento não deve continuar gerando alerta.

---

## 9. Ciclo operacional da cobrança

O fluxo é manual e assistido por alertas.

O gestor:

1. registra a pendência;
2. informa obrigação, valor e vencimento;
3. acompanha o vencimento;
4. verifica externamente se houve pagamento;
5. registra pagamento quando confirmado;
6. se não houver pagamento, entra em contato com a pessoa cobrada;
7. pode enviar valor atualizado com novo prazo;
8. pode negociar acordo;
9. registra o resultado e a próxima ação;
10. repete o ciclo até a resolução financeira.

O sistema deve organizar o trabalho, mas não deve decidir sozinho o próximo passo jurídico.

---

## 10. Obrigações e novos prazos

Cada obrigação deve preservar:

- valor original;
- vencimento original;
- descrição ou referência original.

Se o gestor enviar um boleto ou valor atualizado com nova data:

- não criar uma nova obrigação apenas por isso;
- não apagar o valor e vencimento originais;
- registrar no histórico a tentativa de cobrança;
- registrar o valor solicitado;
- registrar o novo prazo esperado para pagamento.

O objetivo é preservar duas verdades:

- desde quando a dívida está originalmente vencida;
- até quando o escritório está aguardando pagamento na tentativa atual.

O MVP não deve criar um motor universal automático de juros, multa ou correção monetária.

### Valor atual reconhecido e cálculo do saldo

Cada obrigação deve preservar o valor original e pode receber valores atualizados reconhecidos manualmente pelo gestor, incluindo encargos como juros, multa ou correção.

O sistema não calcula esses encargos automaticamente no MVP.

O saldo nunca deve ser digitado manualmente como fonte de verdade.

O saldo atual deve ser derivado a partir de:

- obrigações atualmente exigíveis;
- valores atualizados reconhecidos quando existirem;
- pagamentos e liquidações registrados;
- exclusão das obrigações substituídas por acordo.

Obrigações substituídas permanecem no histórico, mas não entram no saldo exigível atual.

O plano técnico pode propor otimizações de leitura ou cache, mas a fonte de verdade deve continuar sendo os eventos e valores que compõem a cobrança.

---

## 11. Pagamentos e liquidações

### Pagamentos

Devem permitir:

- pagamento total;
- pagamento parcial;
- um pagamento cobrindo várias obrigações do mesmo caso.

Não permitir pagamento atravessando casos diferentes.

A confirmação do pagamento é manual no MVP.

Quando houver juros, multa, correção ou outro encargo reconhecido, esses valores devem ser registrados separadamente do valor original da obrigação. O total recuperado deve refletir o valor efetivamente reconhecido e pago, sem tratar automaticamente a diferença como excedente.

### Liquidações não monetárias

A dívida também pode ser reduzida ou encerrada por:

- veículo;
- imóvel;
- outro bem;
- outro direito ou forma aceita.

Toda liquidação precisa informar quanto do saldo da dívida foi considerado extinto.

O valor atribuído ao bem e o valor reconhecido para liquidação da dívida podem ser diferentes.

O saldo deve ser reduzido pelo valor efetivamente reconhecido como liquidado.

---

## 12. Acordos

Um acordo pertence a uma única cobrança.

Não permitir acordo reunindo obrigações de casos diferentes no MVP.

### Regras

1. O gestor seleciona quais obrigações serão substituídas pelo acordo.
2. As obrigações originais nunca são apagadas.
3. As obrigações substituídas deixam de compor o saldo exigível atual.
4. O acordo cria novas obrigações, normalmente parcelas.
5. O acordo pode substituir apenas parte das obrigações existentes.
6. Parcelas do acordo seguem o mesmo ciclo de verificação de pagamento.
7. Parcela vencida não rompe automaticamente o acordo.
8. O sistema pode alertar sobre atraso.
9. O rompimento do acordo é decisão manual e deve registrar motivo.
10. O acordo pode continuar sendo acompanhado após judicialização.
11. Mudança da pessoa cobrada não deve reescrever o histórico do acordo ou de seus documentos.

Estados mínimos:

- ativo;
- cumprido;
- rompido;
- cancelado.

A carteira pode possuir configuração de tolerância para alertas de atraso, sem romper acordos automaticamente.

---

## 13. Histórico da cobrança

O histórico é parte central da feature.

Deve permitir registrar e visualizar uma linha do tempo com eventos como:

- obrigação criada;
- contato realizado;
- canal utilizado;
- resultado do contato;
- boleto ou valor atualizado enviado;
- novo prazo informado;
- negociação;
- acordo criado;
- acordo rompido ou cancelado;
- pagamento registrado;
- liquidação registrada;
- pessoa cobrada alterada;
- revisão de vínculo;
- judicialização;
- vínculo com pasta judicial;
- encerramento.

Eventos relevantes devem preservar:

- data;
- usuário responsável;
- descrição;
- dados necessários para entender o que ocorreu.

### Histórico operacional não é auditoria técnica

São conceitos diferentes e ambos podem existir para a mesma ação.

**Histórico operacional do Caso de Cobrança:**

- é parte do domínio;
- é visível ao usuário;
- explica o que aconteceu no trabalho de cobrança;
- registra contatos, negociações, decisões, acordos, alterações relevantes e resultados.

**Auditoria técnica:**

- registra alterações de dados;
- identifica quem alterou, quando e o que mudou;
- serve para rastreabilidade e segurança;
- não substitui a linha do tempo operacional.

O plano não deve usar o log técnico de auditoria como substituto do Histórico do Caso de Cobrança.

---

## 14. Próxima ação e alertas

Próxima ação e alerta são conceitos diferentes.

### Próxima ação

É uma tarefa operacional definida pelo gestor.

Exemplos:

- verificar pagamento;
- entrar em contato;
- enviar boleto;
- revisar pessoa cobrada;
- preparar ajuizamento.

O caso deve possuir no máximo uma próxima ação manual ativa por vez.

Ao concluir uma ação, o gestor pode registrar o resultado e definir a próxima.

### Alertas automáticos

São derivados de fatos do sistema.

Exemplos:

- obrigação chegou ao vencimento e precisa ser verificada;
- parcela de acordo venceu;
- próxima ação está atrasada;
- vínculo da pessoa cobrada mudou;
- cobrança precisa de revisão;
- saldo chegou a zero e o caso pode ser encerrado.

O sistema alerta. O gestor decide.

---

## 15. Documentos do Caso de Cobrança

O Caso de Cobrança deve poder possuir documentos mesmo antes de existir Pasta ou processo judicial.

Exemplos:

- termo de acordo;
- boleto enviado;
- comprovante;
- notificação;
- documento relacionado à negociação;
- outro arquivo relevante.

A ausência de Pasta não pode impedir o armazenamento de documentos do Caso de Cobrança.

O plano deve analisar o gerenciador de arquivos já existente e reutilizá-lo quando compatível, evitando duplicar infraestrutura de documentos.

Quando o caso for judicializado:

- os documentos já existentes continuam pertencendo ao Caso de Cobrança;
- não devem ser movidos ou duplicados automaticamente;
- a Pasta judicial continua usando o domínio e as regras já existentes no sistema.

---

## 16. Judicialização

A judicialização não encerra a cobrança.

Ela representa uma mudança de fase.

Fases mínimas:

- extrajudicial;
- judicializada;
- encerrada.

Quando judicializada:

- o caso continua acompanhando saldo, pagamentos, acordos e liquidações;
- o gestor pode vincular a cobrança a uma pasta já existente no domínio de Expediente;
- o link deve levar à pasta do caso;
- não duplicar dentro da feature funcionalidades já existentes para pasta, processo, documentos, prazos ou expediente.

A cobrança só deve ser encerrada quando sua situação financeira estiver resolvida e o gestor confirmar o encerramento.

A integração deve respeitar tenant e permissões do módulo de destino.

---

## 17. Estados e encerramento do Caso de Cobrança

Estados mínimos do Caso de Cobrança:

- ativo;
- judicializado;
- pronto para encerrar;
- encerrado.

O caso nasce ativo.

A judicialização altera o estado para judicializado, mas não encerra o acompanhamento financeiro.

Quando o saldo chega a zero, o sistema indica o caso como pronto para encerrar.

Saldo zero não encerra automaticamente a cobrança.

O sistema deve indicar que o caso está pronto para encerramento.

O gestor confirma manualmente.

Depois de encerrado:

- o histórico permanece disponível;
- o caso não recebe novas obrigações;
- uma nova inadimplência pode gerar um novo caso para o mesmo objeto.

---

## 18. Honorários advocatícios

Cada tenant pode possuir uma configuração padrão, mas cada carteira define sua regra efetiva.

A carteira deve permitir:

- percentual, quando aplicável;
- forma de cobrança;
- ausência de honorário percentual.

### Formas mínimas

1. Honorários acrescidos à dívida.
2. Honorários retidos do valor recuperado.
3. Honorários cobrados separadamente do cliente.
4. Sem honorário percentual.

### Regras

1. A regra da carteira é o padrão para novas cobranças.
2. O caso deve preservar a regra aplicada a ele.
3. Mudanças futuras na carteira não devem recalcular silenciosamente cobranças antigas.
4. Alterações específicas em um caso devem ser manuais, justificadas e auditadas.
5. Os honorários não entram na própria base de cálculo.
6. A base deve considerar o valor reconhecido da dívida na recuperação ou liquidação.
7. O sistema deve separar:
   - honorários projetados;
   - honorários realizados.
8. Honorários recebidos, faturamento, caixa, conciliação e repasses pertencem ao futuro domínio Financeiro.

### Regras por forma de cobrança

#### Honorários acrescidos à dívida

O valor pertencente ao credor e o valor dos honorários do escritório devem permanecer separados.

Em pagamento parcial, a distribuição deve ser proporcional entre dívida do credor e honorários.

Ajuste manual excepcional pode ser permitido, desde que exija motivo e preserve histórico.

#### Honorários retidos do valor recuperado

Os honorários são realizados proporcionalmente a cada recuperação efetiva da dívida.

#### Honorários cobrados separadamente do cliente

O pagamento realizado pelo devedor não quita automaticamente os honorários devidos pelo cliente ao escritório.

A cobrança registra o valor de honorários gerado. O faturamento e o recebimento efetivo pertencem ao futuro domínio Financeiro.

#### Sem honorário percentual

Não existe cálculo percentual de honorários para o caso.

Honorários sucumbenciais não fazem parte do MVP desta regra de honorários da carteira.

---

## 19. Fronteira com o futuro domínio Financeiro

A Gestão de Cobranças e o futuro Financeiro devem ter responsabilidades distintas.

### Gestão de Cobranças é responsável por

- dívida e obrigações;
- saldo devedor;
- pagamentos e liquidações relacionados ao caso;
- acordos;
- honorários projetados;
- honorários realizados;
- divisão econômica esperada entre credor e escritório.

### O futuro domínio Financeiro será responsável por

- contas bancárias e caixa;
- conciliação;
- contas a pagar e a receber;
- faturamento;
- notas fiscais;
- recebimento efetivo de honorários;
- repasses efetivos aos clientes;
- fluxo de caixa.

Regra central:

**A Gestão de Cobranças registra o que aconteceu com a dívida. O Financeiro registra o impacto financeiro real para o escritório.**

O MVP de Cobranças não deve antecipar um mini-ERP financeiro nem criar abstrações financeiras genéricas sem necessidade atual.

---

## 20. Dashboard

O dashboard do MVP deve ser simples e útil.

### Visão financeira

Indicadores prioritários:

- saldo atual em aberto;
- saldo vencido;
- valor recuperado no período;
- honorários projetados;
- honorários realizados.

### Visão operacional

Indicadores prioritários:

- pagamentos a verificar;
- próximas ações atrasadas;
- parcelas de acordo vencidas;
- revisões de pessoa cobrada;
- cobranças judicializadas.

### Resultado

Mostrar pelo menos:

- valor total recuperado;
- valor ainda em aberto;
- taxa de recuperação.

Quando a carteira permitir várias cobranças por objeto, distinguir:

- quantidade de objetos inadimplentes;
- quantidade de cobranças ativas.

O dashboard deve respeitar integralmente tenant e permissões.

---

## 21. Importação em massa

A importação faz parte do MVP, mas não define o domínio.

Os relatórios atuais da contabilidade são apenas uma fonte de entrada específica.

### Ordem desejada

1. núcleo do domínio;
2. UseCases principais;
3. testes;
4. primeira importação real;
5. ajustes encontrados com dados reais;
6. telas operacionais;
7. alertas;
8. dashboard.

### Regras gerais

- a importação deve ocorrer dentro de uma carteira explicitamente escolhida;
- nunca procurar ou vincular automaticamente dados atravessando tenants;
- dados importados devem passar pelas mesmas regras de negócio do cadastro manual;
- o fluxo deve permitir análise e validação antes da confirmação;
- deve existir resultado claro do que foi importado, ignorado ou rejeitado;
- reimportação não pode gerar duplicidades silenciosas.

Não criar agora um importador universal para qualquer planilha.

Cada fonte específica deve adaptar os dados externos para os conceitos gerais da feature.

As regras detalhadas de reimportação e identificação de duplicidades devem ser definidas ao implementar o primeiro importador real, com base em relatórios reais da mesma origem.

---

## 22. Permissões e auditoria

A feature deve integrar o sistema atual de autorização em camadas.

No MVP, considerar pelo menos capacidades separadas para:

- visualizar cobranças;
- gerenciar cobranças;
- gerenciar carteiras e configurações;
- registrar ou alterar movimentações financeiras.

Não criar no MVP um advogado responsável obrigatório por carteira ou caso.

Usuários autorizados podem operar os casos e o histórico registra quem executou cada ação.

A auditoria deve cobrir especialmente:

- criação e alteração de obrigações;
- pagamentos e correções relevantes;
- liquidações;
- acordos;
- mudança de pessoa cobrada;
- alteração de honorários;
- judicialização;
- vínculo com pasta;
- encerramento.

Registros financeiros e jurídicos relevantes não devem ser apagados silenciosamente.

Quando necessário, correções relevantes devem preservar rastreabilidade por meio da auditoria existente.


---

## 23. Regras invariáveis

Estas regras não devem ser reinterpretadas durante o planejamento:

1. Todo dado pertence a um tenant.
2. Todo credor é um cliente já cadastrado no sistema.
3. O devedor não é cliente do escritório por causa da cobrança.
4. Toda carteira pertence a um cliente/credor.
5. Toda cobrança pertence a exatamente um objeto.
6. Um objeto pode ter uma ou várias cobranças ativas conforme configuração da carteira.
7. Cada cobrança ativa possui exatamente uma pessoa cobrada atual.
8. Nunca existem duas pessoas sendo cobradas simultaneamente no mesmo caso.
9. A pessoa cobrada permanece até alteração manual.
10. Mudanças de vínculos não alteram automaticamente a pessoa cobrada.
11. Pessoas anteriores e vínculos anteriores permanecem no histórico.
12. Pagamentos não atravessam cobranças.
13. Acordos não atravessam cobranças no MVP.
14. Obrigações substituídas por acordo nunca são apagadas.
15. Obrigações substituídas deixam de compor o saldo exigível.
16. Judicialização não encerra a cobrança.
17. A cobrança continua sendo acompanhada até resolução financeira e encerramento manual.
18. Honorários são separados da dívida pertencente ao credor.
19. A carteira define a regra padrão de honorários.
20. O saldo é derivado das obrigações exigíveis e das liquidações; não é um valor manual independente.
21. A pessoa cobrada pode existir sem vínculo formal com o objeto, mas continua sendo exatamente uma por caso.
22. A Gestão de Cobranças registra os efeitos sobre a dívida; caixa, faturamento, conciliação e repasses pertencem ao futuro Financeiro.
23. Pessoa, vínculo com objeto e pessoa cobrada atual são conceitos diferentes.
24. CPF e CNPJ são opcionais para Pessoa; quando informados, ajudam a evitar duplicidades somente dentro do mesmo tenant.
25. O Caso de Cobrança pode possuir documentos antes da judicialização e sem depender da existência de Pasta.
26. Histórico operacional do Caso de Cobrança e auditoria técnica são conceitos diferentes.
27. Usar sempre a nomenclatura Carteira de Cobrança, Objeto de Cobrança e Caso de Cobrança para evitar ambiguidade.
28. O sistema alerta e registra; decisões jurídicas sensíveis continuam sendo humanas.

---

## 24. Fora do escopo do MVP

Não incluir inicialmente:

- motor universal de juros e correção monetária;
- emissão automática de boletos;
- conciliação bancária;
- Pix integrado;
- cobrança automática por WhatsApp ou e-mail;
- protesto;
- Serasa;
- construtor livre de entidades e campos;
- workflow totalmente configurável;
- importador universal de planilhas;
- múltiplas pessoas cobradas simultaneamente no mesmo caso;
- pagamentos atravessando cobranças;
- acordos atravessando cobranças;
- honorários sucumbenciais;
- contas bancárias e caixa;
- conciliação financeira;
- faturamento e notas fiscais;
- contas a pagar e a receber;
- repasses financeiros aos clientes;
- duplicação das funcionalidades já existentes de Expediente, Pasta e Processo.

---

## 25. Orientações para o ClaudeCode

Antes de propor o plano:

1. analisar a arquitetura atual do projeto;
2. identificar os domínios já existentes que devem ser reutilizados ou integrados;
3. estudar especialmente:
   - Clientes;
   - Expediente;
   - Pastas;
   - Processos;
   - Documentos;
   - permissões e autorização;
   - auditoria existente;
4. identificar riscos de multi-tenancy e vazamento entre escritórios;
5. verificar impacto em funcionalidades já usadas em produção;
6. evitar duplicação de conceitos existentes;
7. não criar abstrações genéricas desnecessárias ou plataforma low-code;
8. não reinterpretar as regras invariáveis deste documento.

Depois da análise, apresentar um plano de implementação por etapas.

O plano deve:

- começar pelo domínio e pelos UseCases;
- prever testes antes das camadas externas;
- dividir o trabalho em entregas pequenas e verificáveis;
- destacar migrations e impacto em dados existentes;
- mostrar integrações com domínios atuais;
- destacar riscos e decisões técnicas ainda necessárias;
- prever a importação depois do núcleo e dos testes;
- separar claramente MVP de evoluções futuras;
- manter a nomenclatura oficial desta SPEC;
- distinguir Histórico do Caso de Cobrança de auditoria técnica;
- considerar os requisitos de interface e UX desde o planejamento das telas.

**Não iniciar a implementação antes da revisão e aprovação do plano.**


---

## 26. Requisitos de interface e UX

A interface deve seguir minimamente o padrão visual e estrutural atual do sistema e tratar UX como requisito funcional.

Princípios obrigatórios:

- ser fácil de usar e autoexplicativa;
- seguir sequência lógica de operação;
- priorizar visualmente o que exige atenção;
- permitir que o usuário identifique rapidamente o que precisa fazer;
- usar tooltips quando uma ação, indicador ou conceito puder gerar dúvida;
- usar explicações curtas no próprio contexto quando necessário;
- evitar telas carregadas e excesso de informação simultânea;
- diferenciar claramente estados normais, pendentes, vencidos, atrasados, em revisão e prontos para ação;
- reduzir cliques e decisões repetitivas;
- manter consistência entre listas, detalhes, formulários, alertas, histórico e dashboard;
- considerar sempre a próxima ação mais provável do usuário em cada tela.

Experiência desejada:

**Ao abrir a área de cobranças, o usuário deve conseguir bater o olho e entender o que está acontecendo, o que exige atenção e qual é a próxima ação.**

O plano de implementação deve considerar esses requisitos desde o desenho das telas, e não como acabamento posterior.
