# Indicar o BlueJus

**Risco:** MÉDIO (identidade de quem indica, e-mail a terceiro) · **Frente:** `indicar-bluejus` (sessão 9b) · **Data:** 19/10/2026

## Estado da frente

- 19/10 noite: spec e contrato com a 5d (fatia 4 da Comercial). Frente empilhada sobre `comercial-1a` (#99, que está sobre o #87):
  decisão do coordenador, deploy junto. Usa o Carteiro (#99) e o `DescadastroDeEmailInterface` (#87).
- Migration: uma, no fim da fila (coordenador); timestamp do momento, renomeada na vez.
- A frente era da 7d (parada aguardando o dono); passou para a 9b em 19/10.

## O desenho

- Menu do usuário: `03 - EXPEDIENTE - VS 18.10.26.dc.html` L320 (item "Indicar o BlueJus", `bi-megaphone`, rótulo "nova conta", para todos,
  depois de "Convites do escritório") e L687 (abre a janela).
- Janela: `bj-contas.js` L98-133 (`indicar()`).
- Painel do Master: `05 - Perfis 1.2 - VS 18.10.26.dc.html` L269-275 (marcação) e L572-575 (lógica), card "Indicações do BlueJus" na seção
  Comercial. **A tela do card é da 5d (fatia 4)**; esta frente entrega os UseCases que ela consome.

## Contrato com a 5d (fechado em 19/10)

Domínio novo `App\Indicacao`. Tudo de plataforma (sem tenant); autorização de Master no controller da 5d.

- `ObterPainelDeIndicacoesUseCase::executar(int $pagina = 1, int $porPagina = 50): PainelDeIndicacoesOutput`
  - `PainelDeIndicacoesOutput{int enviadas, int ultimas24h, int viraramConta, LimitesDeIndicacaoOutput limites,
    list<AlertaDeIndicacaoOutput{string texto, \DateTimeImmutable em}> alertas (3 últimos), list<IndicadorOutput> indicadores
    (ordem: ultimas24h desc, total desc), int totalDeIndicadores, int pagina, int porPagina}`
  - `IndicadorOutput{int userId, string nome, string email, ?string escritorio, int total, int ultimas24h, int ultimos30d,
    int contasCriadas, ?\DateTimeImmutable ultimaEm, bool pausado, bool acimaDoAlerta}`
  - `LimitesDeIndicacaoOutput{bool pausadoParaTodos, int porDia, int porMes, int alertaEm24h}`
- `AlterarLimitesDeIndicacaoUseCase::executar(AlterarLimitesDeIndicacaoInput{porDia, porMes, alertaEm24h} (>= 1), User $master): LimitesDeIndicacaoOutput`
- `PausarIndicacoesParaTodosUseCase::executar(bool $pausar, User $master): void`
- `PausarIndicacoesDoUsuarioUseCase::executar(int $userId, bool $pausar, User $master): void`
- Envio do convite por e-mail: Carteiro, `TipoDeEnvio::Lista`, lista `indicacao`, origem `indicacao`, escopo `null`,
  `nomeDoRemetente` "{nome} via BlueJus", `motivoDoEnvio` "Você recebeu este e-mail porque {nome} indicou o BlueJus para você."
  `bloqueado_descadastro` grava a indicação igual e mostra o mesmo sucesso (não revela quem saiu da lista). O "Últimos disparos" da 5d
  filtra a origem `indicacao` no registro de envios dela.

## Modelo de dados (uma migration)

- `indicacao`: `id`, `indicado_por_id` (User, CASCADE), `tenant_origem_id` (Tenant, SET NULL, só para mostrar o escritório),
  `canal` (`email` | `whatsapp` | `link`), `email` NULL, `nome` NULL (só no canal e-mail), `criada_em`.
  Índices `(indicado_por_id, criada_em)` e `lower(email)` (funcional: atenção ao ritual `criar-migration`).
- `indicacao_codigo`: `user_id` PK, `codigo` varchar(12) único, aleatório (não derivado: hash seria adivinhável).
- `indicacao_config`: uma linha (`id = 1`): `pausado_para_todos`, `limite_dia` (20), `limite_mes` (200), `alerta_24h` (30), `alterado_por_id`, `alterado_em`.
- `indicacao_pausa`: `user_id` PK, `pausado_por_id`, `pausado_em`.
- `indicacao_alerta`: `id`, `indicado_por_id`, `texto`, `em` (os 3 últimos vão ao card).
- Não multi-tenant (plataforma). Fora do `audit_log` (sem tenant); classificar nos testes de cobertura e na purga de usuário/escritório onde couber.

## Comportamento

1. **Item do menu** "Indicar o BlueJus" para todos os logados (com ou sem escritório), no `templates/menu_usuario/_dropdown.html.twig`
   entre "Convites do escritório" e "Trocar de conta". Abre a janela (modal) com os textos exatos do desenho (bj-contas.js L112-124).
2. **Cadastro completo** para enviar: nome com 2 palavras ou mais, telefone e CPF (UserProfile). Senão: "Complete seu cadastro para
   enviar convites" / "Por segurança, o convite sempre leva o seu nome, então precisamos do seu {lista}." e "Completar meu cadastro" (Meu Perfil).
3. **Travas**, nesta ordem, com as mensagens do desenho: pausa global; pausa da pessoa; limite de 24h (janela móvel); limite de 30 dias.
   Os três canais contam. Contagem protegida contra corrida (lock por usuário).
4. **E-mail:** valida o formato ("Informe um e-mail válido."); se o e-mail já é membro do MEU escritório: "Esta pessoa já faz parte da sua
   conta." (não consulta contas de fora: evita descobrir quem tem conta). Envia pelo Carteiro com **modelo fixo** (ver "Para o dono" 2).
   Sucesso: "Convite enviado para {email}. Obrigado por indicar o BlueJus!"
5. **WhatsApp:** registra (canal whatsapp) e abre `wa.me/?text=` com o texto da caixa editável. "Mensagem aberta no WhatsApp."
6. **Copiar link:** registra (canal link) e copia. "Link copiado."
7. **Link curto** `/i/{codigo}`: rota pública; guarda o código na sessão e leva ao cadastro (`/cadastro`). A atribuição do código ao
   cadastro novo entra quando o #101 estiver no master (o cadastro novo é dele).
8. **"Viraram conta"**: contado na leitura: indicação por e-mail cujo e-mail (normalizado) tem conta criada DEPOIS da indicação.
9. **"SUAS INDICAÇÕES ({total})"**: as 8 mais recentes com "CONTA CRIADA" (pela regra 8) ou "ENVIADA". Vazio: "Nenhuma indicação ainda."
10. **Alerta ao Master** "Disparo alto de convites": quando a contagem de 24h da pessoa CRUZA `alerta_24h` (antes < alerta <= depois),
    uma vez; grava `indicacao_alerta` e avisa os Masters no sino/Central (texto do desenho L126).

## Para o dono (confirmar)

1. Padrões do desenho: 20 por dia e alerta em 30 por 24h. Com o envio travado em 20, o alerta nunca dispara. Mantidos os números do
   desenho até a escolha; o alerta dispara ao cruzar o limite (não só no valor exato).
2. O texto editável vai só no WhatsApp; no e-mail o corpo é um modelo fixo com o nome de quem indica e o link (texto livre em e-mail
   da plataforma vira canal de spam). Desvio por segurança, isolado.
3. LGPD: e-mail a quem foi indicado por terceiro. Vai com o motivo do envio verdadeiro, o link "Não quero mais receber" e consulta o descadastro.
