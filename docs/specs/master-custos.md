# Master: seção Custos e infraestrutura

Desenho: `docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html` (template L260-270, lógica `csKpis`,
`csCat`, `csMarg`, `csProj`, `csSalvar`, `csCopiar`, `csCsv`, `csImport`, L917-937; texto vazio L669).
Risco: MÉDIO (dado financeiro de plataforma, só Master, auditado; não toca cobrança de cliente). Frente: Desempenho.
Depois dos PRs do Desempenho (spec `master-desempenho.md`); reaproveita o mesmo molde de seção externa do painel.

## 1. O que o desenho pede

Seção "Custos" do painel `/master`:

- Barra: seletor de mês (`type=month`), Copiar do mês anterior, Importar CSV, Exportar, Lançar custo.
- 5 KPIs: Custo do mês (nº de lançamentos), Receita recorrente (assinaturas de espaço e convênios regionais),
  Margem (verde, vermelho se negativa; % da receita), Custo por conta (e por usuário), Custo por GB (GB armazenados).
- Cartão "Custos por categoria": 9 categorias (Armazenamento, Banco de dados, Hospedagem e processamento, Tráfego,
  Backups, APIs de IA, Notificações e e-mail, Integrações, Outros), barra proporcional, "não lançado" quando vazia.
- Cartão "Margem por plano e projeção": por plano, contas, preço, custo (GB do plano x custo por GB) e margem; projeção
  de 3, 6 e 12 meses mantendo o mês selecionado; nota "Projeção mantém os valores do mês selecionado".
- Cartão "Lançamentos do mês": categoria, fornecedor, valor, origem (Medido verde, Importado do fornecedor azul,
  Estimado âmbar), observação, excluir (com confirmação). Vazio: "Nenhum custo lançado neste mês. Use Lançar custo,
  Importar CSV ou Copiar do mês anterior."
- Janela "Lançar custo · AAAA-MM": categoria, valor mensal (R$), fornecedor, origem, observação; erro "Informe o valor mensal."
- Cada lançamento é auditado ("Custo lançado: categoria valor").

## 2. Decisões (o desenho é do protótipo, o real grava no servidor)

- D1. O protótipo guarda em `localStorage` (`bj-custos`). No real vai ao banco, global (sem tenant, como `RecursoEstado`).
- D2. Valor em centavos (`int`), nunca float. Mês como `AAAA-MM` (char 7). Um lançamento por linha; sem unicidade (o mesmo
  fornecedor pode ter dois lançamentos no mês).
- D3. Receita recorrente é calculada, não digitada: soma, por conta ATIVA (suspensa e em quarentena ficam fora), do preço do
  plano no catálogo (`CatalogoDePlanos`, centavos) mais o valor do convênio da UF quando o modo é `dif` ou `sem`
  (`ConvenioDaUf`), como no desenho (`rec`). É a receita de hoje, não histórica: ao escolher um mês passado a receita
  mostrada é a atual, com a nota "Receita atual, não histórica". Recomendação: aceitar (histórico de receita é frente de
  faturamento, depende do gateway; `docs/specs/gateway-pagamentos.md`).
- D4. GB armazenados: soma do consumo atual das contas (mesma fonte do KPI Armazenamento da visão geral). Custo por GB =
  (Armazenamento + Backups do mês) / GB armazenados, como no desenho. Sem GB ou sem lançamento: "não lançado" / "-".
- D5. Margem por plano: custo do plano = custo por GB x cota do plano (GB do catálogo). Plano sem cota: "-".
- D6. Origem: `medido`, `importado`, `estimado`. Copiar do mês anterior transforma `medido` em `estimado` (o valor deixou de
  ser medido). Importar CSV marca `importado`.
- D7. CSV: exportar usa `;` e BOM UTF-8, colunas Mês, Categoria, Fornecedor, Valor, Origem, Observação, valor com vírgula.
  Importar lê Mês (AAAA-MM), Categoria, Fornecedor, Valor (4 primeiras obrigatórias); categoria desconhecida vira "Outros";
  linha com valor <= 0 é ignorada; resposta diz quantas entraram e quantas foram ignoradas. Arquivo <= 1 MB, <= 1000 linhas.
- D8. Excluir lançamento é exclusão de verdade (dado do Master, sem LGPD), auditada (`Auditavel` registra o removido).
- D9. As 9 categorias são um enum fixo, rótulos no catálogo pt_BR. Categoria nova exige código (recomendação: aceitar;
  se o dono quiser editar categorias, vira configuração no Master em PR próprio).
- D10. "Sem integração financeira": nada é medido automaticamente neste ciclo. Origem `medido` só existe se o Master
  escolher; nenhuma coleta automática de custo é inventada.
- D11. Dinheiro formatado com o `BJFormato` do catálogo; sem travessão em texto visível (regra do dono).

## 3. Modelo

`custo_lancamento` (id, mes char(7), categoria varchar(30), fornecedor varchar(80) null, valor_centavos int > 0,
origem varchar(10), observacao varchar(200) null, lancado_por_id FK user SET NULL, lancado_em timestamp).
Índice (mes). Entidade `LancamentoDeCusto` (`Auditavel`), enums `CategoriaDeCusto`, `OrigemDoCusto`. Migration com o
número que o Coordenador fornecer (pedir antes do PR 1).

## 4. Entregas (PRs pequenos, nesta ordem)

1. Modelo + migration + `ConsultarCustosUseCase` (KPIs, categorias, margem por plano, projeção, lançamentos) +
   `GET /master/dados/custos?mes=AAAA-MM`.
2. Seção na tela (`_custos.html.twig`, `custos.js`, CSS `.cst-*`, seção externa `data-sec-externa="custos"`, só leitura).
3. Gravação: lançar, excluir (confirmação em janela do desenho), copiar do mês anterior; auditoria de plataforma e
   `RotuloDeAuditoria`.
4. CSV: exportar e importar.

## 5. Testes

Unit: enum e valor (centavos, > 0), receita por plano e convênio, custo por GB, margem negativa, projeção, copiar
(medido vira estimado), parser de CSV (BOM, `;`, vírgula decimal, categoria desconhecida, linha inválida).
Functional: 403 para quem não é Master, CSRF, lançar/excluir/copiar/importar, auditoria sem tenant, exportação com BOM.
Tela: arranjo por filho direto (barra, 5 KPIs, 2 cartões na grade, lista), sem `.card`/`.modal` do sistema.

## 6. Pontos sem decisão do dono

Nenhum bloqueia. Receita histórica (D3) e categorias editáveis (D9) seguem a recomendação; se o dono pedir o contrário,
vira frente própria.
