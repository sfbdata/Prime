# Cobrança — Resultado do contato (opções) + "Relato do Atendimento"

> Ponto **#2** dos ajustes pós-taxa. Risco **BAIXO**. Independente da feature de taxa. Sem migração de banco
> (o resultado é gravado como texto no payload do `EventoHistorico`, não em coluna tipada).

## 1. Objetivo

No registro de um contato de cobrança (`RegistrarTentativaCobrancaType`): ajustar as opções de **Resultado** e
renomear o rótulo **"Observação" → "Relato do Atendimento"**.

## 2. Mudanças

### 2.1. Enum `App\Cobranca\Enum\ResultadoContato`
- **Acrescentar** três casos: `Atendido` ("Atendido"), `PediuRetorno` ("Pediu retorno"),
  `InformouOutroNumero` ("Informou outro número").
- **`PrometeuPagar` NÃO é removido do enum** — fica para **ler contatos antigos** já gravados com
  `prometeu_pagar` (o módulo está em prod; remover o case quebraria `ResultadoContato::from()` ao exibir o
  histórico). Ele apenas **sai da lista selecionável** (§2.2).
- Manter os existentes: `NaoAtendido`, `CaixaPostal`, `NumeroErrado`, `Outro`.
- Método novo `selecionaveis(): array` devolvendo as opções que aparecem no formulário (todas **menos**
  `PrometeuPagar`), na ordem: **Não atendido · Atendido · Caixa postal · Número errado · Pediu retorno ·
  Informou outro número · Outro**. `label()` continua cobrindo `PrometeuPagar` (exibição do legado).

### 2.2. Form `RegistrarTentativaCobrancaType`
- O campo `resultado` deixa de aceitar o enum inteiro e passa a usar `choices: ResultadoContato::selecionaveis()`
  (EnumType com `choices` explícito). Assim ninguém escolhe "Prometeu pagar" em contato novo; contatos antigos
  ainda renderizam.
- O rótulo do campo `observacao` muda de **"Observação (opcional)"** para **"Relato do Atendimento (opcional)"**.
  (A propriedade/coluna/payload continua `observacao` — muda só o rótulo visível.)

### 2.3. Exibição no histórico
Onde o contato é mostrado (evento de histórico no `caso/_acoes_modais.html.twig` e na timeline do caso), trocar
o texto visível **"Observação" → "Relato do Atendimento"**.

## 3. Testes
- Unit do enum: `selecionaveis()` **não** contém `PrometeuPagar` e está na ordem esperada; `from('prometeu_pagar')`
  ainda resolve e `label()` devolve "Prometeu pagar" (legado legível).
- Functional: submeter um contato com `resultado = Atendido` grava o evento; a tela mostra "Relato do Atendimento".
- Suíte de Cobrança verde + global verde.

## 4. Fora de escopo
- Migrar/retro-rotular contatos antigos (ficam legíveis via `label()`).
- Tornar `resultado` uma coluna tipada (segue no payload do evento).
