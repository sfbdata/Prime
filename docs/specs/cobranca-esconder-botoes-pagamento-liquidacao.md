# Cobrança — Esconder "Registrar pagamento" e "Registrar liquidação"

> Ponto **#3** dos ajustes pós-taxa. Risco **BAIXO** (só UI). **⚠️ Colide com a feature de taxa** (edita o mesmo
> `objeto/show.html.twig`) → **base de implementação: a branch de encargos**, e **coordenar** com aquele chat (ou
> fazer depois que ela estabilizar). Fácil candidato a o próprio chat da taxa aplicar junto do Twig dele.

## 1. Objetivo

Tirar da tela os botões **"Registrar pagamento"** e **"Registrar liquidação"**, deixando **apenas "Receber"**
(direto na obrigação). **O motor/UseCases permanecem** (`RegistrarPagamentoUseCase`, `ReconciliadorLiquidacao`,
`Liquidacao`…) — é remoção **só de UI**, reversível.

## 2. Onde ficam os botões

- `templates/cobranca/caso/_acoes_modais_financeiro.html.twig` (ações financeiras do caso).
- `templates/cobranca/objeto/show.html.twig` e `objeto/_partials/_movimentos.html.twig` / `_divida.html.twig`.
Mapear os gatilhos exatos por grep de "Registrar pagamento"/"Registrar liquida" antes de esconder; **manter** o
"Receber" da obrigação intacto.

## 3. Mudança

Ocultar (não deletar a lógica) os disparadores dos modais de pagamento e liquidação — comentar/remover os botões e,
se algum modal ficar órfão, escondê-lo. Não mexer nos controllers/rotas/UseCases (podem seguir acessíveis por rota,
só sem botão — decisão "por enquanto" do dono).

## 4. Testes
- Functional: a tela do objeto **não** exibe "Registrar pagamento"/"Registrar liquidação"; **exibe** "Receber"; o
  fluxo de "Receber" segue funcionando (não regrediu).
- Suíte de Cobrança verde + global verde.

## 5. Coordenação (obrigatória)
`objeto/show.html.twig` é editado pela feature de taxa por-obrigação (modal com espelho %↔R$). **Não** aplicar em
paralelo na mesma base: ou o chat da taxa esconde os botões junto, ou este ponto entra **depois** que a taxa
estabilizar, rebaseado sobre ela. Nunca dois pilotos no mesmo arquivo/branch.
