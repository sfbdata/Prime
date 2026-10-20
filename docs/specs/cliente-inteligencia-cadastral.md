# Spec: Inteligência cadastral do cliente (desenho 02, VS 18.10.26)

## Estado da frente (releia antes de continuar; atualizado em 19/10/2026)

- Branch `cliente-inteligencia-cadastral`, **empilhada sobre o #112** (`cliente-historico`, da 9b). Merge
  depois do #112. Risco **MÉDIO** (dado pessoal e identidade do cliente).
- Fatia em andamento: **F-A** (só regras internas, sem fonte externa e sem IA).
- F-B, F-C e F-D **esperam o dono** (D1 a D4 abaixo).
- **PR em rascunho** (base `cliente-historico`, #112), migration `Version20261021150000`. Suíte completa OK (10509).
- **Revisão cruzada da f6 (20/10)**: aprovado com ressalvas, nada ALTO. Corrigido: título e ícone neutros
  ("Conferência do cadastro", `bi-clipboard-check`; o nome fica com o dono, item 8 do pacote); a pendência do
  cadastro não conta o vínculo de pasta que quem olha não vê (§2.3); o POST de "Vincular" repete as regras da
  tela (CPF/CNPJ válido, categoria que o cadastro ainda não mostra) pelo `RegrasDosEncontrados`; unit do
  `VincularDocumentoDaPastaAoClienteUseCase`.
- **Depois dos merges** (coordenador, 20/10): o **#68 entra antes do #124**. Com o #68 no master, o
  `DocumentosVisiveisDoCadastro` passa a respeitar também a `VisibilidadeDasAbasDaPasta` (aba Documentos oculta:
  hoje deixa ver nome do arquivo, NUP e data, e vincular). Com o #120 no master, trocar o laço por pasta pelo
  `niveisEmLote` (N+1) e escrever o functional do cadeado.

**Para o dono** (pacote "IA x regras", levado pelo coordenador em 19/10):
- **D1. Rótulo "IA".** O desenho chama de IA o que é regra (l.3391, 3394, 3401, 3407). As regras 1 e 7 do
  `app/src/Inteligencia/CLAUDE.md` proíbem: nada com rótulo de IA sem vir do modelo, e CPF, contatos,
  endereço e conteúdo de documento nunca entram no contexto. A F-A usa rótulos neutros, num ponto único
  (`RotulosDaInteligenciaCadastral`), marcados **"a confirmar"**.
- **D2. "Atualizar sozinho quando a confiança for alta"** contradiz a decisão do dono no E12i ("sugestão
  da leitura nunca aplicada sozinha").
- **D3. Motor de OCR no servidor** (E12i: "leitura dos documentos no próprio servidor, sem IA externa").
- **D4. Consulta de CPF em fonte oficial** (Receita/Serpro): custo, contrato e base legal.

**Contratos com outras sessões**:
- 9b (19/10): esta frente cria `App\Cliente\Service\DocumentoDoCliente::digitos(string): string` (só
  dígitos, `preg_replace('/\D/', '', …)`, o mesmo critério do `ClientesDuplicadosDaPasta::digitosDe` do
  #107). Sem `chave()` (a F-A não compara na tela). Depois que esta frente e o #107 estiverem no master, a
  9b troca o `ClientesDuplicadosDaPasta` para delegar a ele. Não mexer no #107.
- O histórico das ações usa o `HistoricoDoCliente` do #112 (só rótulos, nunca valores).

## 1. O que o protótipo faz (investigação de 19/10)

A "Inteligência cadastral" (aba Documentos do modal do cliente, l.740 a 815; script l.3333 a 3408) cruza
uma tabela **fixa** no código (`EXTRACOES`, 6 documentos de um único cliente) com o cadastro, por **regras
de pontuação** (CPF +50, nome +20, nascimento +15, telefone/e-mail/CEP/pasta +5; corte 55). A "confiança"
é a pontuação do documento inteiro. Não há IA nem consulta externa. O comentário do código diz que o real
seria "OCR + extração por IA a cada upload".

## 2. F-A: o que entra

### 2.1 Onde e quando
- Bloco no topo da aba **Documentos** do modal do cliente (cadastro já salvo), com o cabeçalho do desenho
  (l.743 a 748): ícone, título "Inteligência cadastral" e subtítulo.
- Aparece para **PF com CPF válido** (dígito verificador, `DigitoVerificador::cpfValido`) e para **PJ com CNPJ
  válido** (correção: o desenho deixa PJ de fora, l.3357). Sem documento válido, o bloco não aparece.
- Carregado sob demanda ao abrir a aba (`GET`), como o desenho (l.3518).

### 2.2 Alertas (l.749 a 751, 3362 a 3367), textos do desenho
- **"Possível homônimo."** "N outro(s) cadastro(s) com o mesmo nome e CPF diferente. Documentos só com o
  nome não atualizam este cadastro." (PJ: "mesma razão social e CNPJ diferente"). Regra: outros clientes do
  **mesmo escritório** com o nome normalizado igual (sem acento, sem diferença de caixa, espaços únicos) e
  documento (dígitos) diferente, contados no banco (COUNT, sem teto). Correção: o desenho só pega nome idêntico; aqui a normalização tira
  acentos e pontuação.
- **"Mesmo CPF com outro nome."** "\"<nome>\" em <origem>. Pode ser abreviação ou cadastro duplicado;
  confira antes de consolidar." Fontes internas, no mesmo escritório, comparando os **dígitos**:
  - outro cliente com o mesmo documento escrito de outro jeito ("006.462.251-70" e "00646225170") e nome
    diferente: origem "outro cadastro de cliente"; o nome só aparece para quem pode ver o outro cadastro;
    para os demais, o alerta sai sem o nome (mesma regra do `existenteVisivel`);
  - pessoa da Cobrança com o mesmo CPF/CNPJ e nome diferente: origem "Cobranças", **só se o usuário tem
    acesso ao módulo de cobranças** (`PermissionChecker::canAccessModule`).
- **"Identidade não confirmada"** depende da leitura dos documentos: fica para a F-B.
- Nenhum CPF/CNPJ aparece no texto do alerta; só o nome e a origem.

### 2.3 "Encontrados no menu Documentos" (l.773 a 780, 3387)
- Documentos das **pastas em que o cliente está vinculado**, nas categorias **Identificação** e
  **Comprovante de residência**, não excluídos (fora da lixeira), de pasta viva (pasta na lápide,
  `excluidaEm` preenchido, fica fora, também do subtítulo), que o usuário pode ver (permissão de leitura na
  pasta), que ainda não estão vinculados ao cadastro e **só da categoria que o cadastro ainda não tem**
  (desenho l.3387, `!(f.docs || {})[x.tipo]`): com um documento de identificação no cadastro (anexo próprio
  ou vínculo que o usuário vê), nenhum documento de identificação aparece nos Encontrados; idem para o
  comprovante.
- Linha: "<arquivo> · Documento de identificação | Comprovante de residência · Pasta N · dd/mm/aaaa" (data
  do envio do documento à pasta; correção: no desenho a data era escrita à mão).
- **"Vincular ao cadastro"** (permissão de edição do cliente): cria o vínculo do documento da pasta com o
  cadastro, **sem cópia do arquivo** (`ClienteDocumento` aponta para o `PastaDocumento`), e registra no
  Histórico do cliente pelo `HistoricoDoCliente` ("Documento vinculado a partir do menu Documentos", só
  rótulos). Desvincular segue o fluxo de documento do cadastro.
- **Arquivo** (skill `armazenamento-de-arquivos`): o documento vinculado **não tem arquivo próprio**; abrir
  e baixar usam o arquivo do `PastaDocumento`, com a permissão da pasta conferida de novo. Remover o
  vínculo do cadastro **nunca** apaga o arquivo da pasta. Se o documento da pasta for excluído, o vínculo
  some da lista do cadastro (não fica link quebrado). O vínculo cuja pasta o usuário não pode ver não aparece
  para ele na janela de Cadastro nem na página do cliente, **e não resolve as pendências do cadastro para ele**
  (decisão do coordenador em 20/10, revisão cruzada da f6: contar o vínculo denunciaria documento em pasta
  escondida; com o cadeado isso pesa). Sem usuário logado, só o anexo próprio conta. O
  vinculado não é editável na página do cliente (sem o botão Editar; a rota de edição dá 403): só desvincular.
  A purga do escritório não trata o vinculado como arquivo do cliente (a chave vazia não entra).

### 2.4 Rótulos "a confirmar" (D1)
Ponto único `RotulosDaInteligenciaCadastral` (servidor) com:
- subtítulo: "N documento(s) deste cliente em P pasta(s)" (sem o "· cadastro em dia com os documentos",
  que afirmaria uma leitura que ainda não existe);
- nota fixa neutra no lugar da l.3407: "Nome indica uma possibilidade; CPF e outros dados confirmam a
  identidade. Nada muda no cadastro sem validação, e cada mudança fica no Histórico.";
- nenhuma ocorrência de "IA" na F-A. **Título e ícone neutros** (regra 1 de `app/src/Inteligencia/CLAUDE.md`,
  revisão cruzada da f6 em 20/10): "Conferência do cadastro" com `bi bi-clipboard-check`, no lugar do
  "Inteligência cadastral" com `bi-stars` do desenho. O nome definitivo fica com o dono (item 8 do pacote);
  trocar é mudar só `TITULO` e `ICONE` em `RotulosDaInteligenciaCadastral`.

### 2.5 Fora da F-A
Sugestões de atualização com confiança, Validar/Editar/Manter anterior/Rejeitar, "Validar todas", linha do
tempo da identidade (F-B, D1 e D3); "Atualizar sozinho" (F-C, D2); confirmação em fonte oficial (F-D, D4).

## 3. Contrato
- `GET /cliente/{id}/inteligencia-cadastral` `cliente_inteligencia_cadastral` (JSON; cliente do escritório
  e visível ao usuário, senão 404): `{on, titulo, subtitulo, alertas: [{tipo (homonimo|mesmoDocumento), ic,
  negrito, texto}], encontrados: [{id, arquivo, categoria, categoriaRotulo, pasta, data,
  podeVincular}], nota, rotulos: {icone, encontrados, vincular, vincularErro, salvarAntes}}`. `rotulos` vem de
  `RotulosDaInteligenciaCadastral`: o JS não tem texto próprio do bloco.
- `POST /cliente/{id}/inteligencia-cadastral/vincular/{documentoId}` `cliente_inteligencia_cadastral_vincular`
  (CSRF no header; edição do cliente e leitura da pasta; 404 se o documento não é de uma pasta viva do
  cliente). Resposta `{ok, vinculado, mensagem}`: `vinculado: false` quando o vínculo já existia, inclusive
  em dois pedidos simultâneos (o índice único recusa o segundo; 200, não 500).
- Migration `Version20261021150000`: coluna `pasta_documento_id` em `cliente_documento`, FK com
  **`ON DELETE CASCADE`** (decidido: a purga do documento da pasta derruba o vínculo, que não tem arquivo
  próprio; na lixeira o vínculo fica e só some da tela) e índice único `(cliente_id, pasta_documento_id)`.

## 4. Verificação
- Unit: normalização de nome, homônimo PF/PJ, mesmo documento com outro nome, filtro de permissão da
  Cobrança, rótulos sem "IA".
- Functional: bloco só com documento válido; outro escritório nunca aparece; sem acesso à Cobrança a
  origem "Cobranças" não aparece; documento de pasta que o usuário não vê não aparece; vincular cria o
  vínculo sem cópia e o registro no histórico; CSRF; 404 para cliente de outro escritório.
- Navegador: comparar com o desenho item a item (l.740 a 791) e reportar ✓/✗.
