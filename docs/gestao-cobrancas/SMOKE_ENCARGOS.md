# Guia de smoke — Encargos separados e configuráveis em cascata

> Roteiro manual para validar a feature no **dev** antes de mergear/deployar. ~10 min.
> Dado do dev é de TESTE (dump de prod). Feature na branch `cobranca-encargos-cascata`.

## 0. Pré-requisitos

```bash
# Se os containers estiverem parados (o WSL às vezes derruba):
docker start jusprime_db_dev jusprime_php_dev jusprime_nginx_dev

# Estar na branch da feature (o dev monta o checkout principal):
cd /home/prime/projetos/jusprime && git switch cobranca-encargos-cascata

# Se trocou de branch/CSS não atualizar, limpe o cache do Twig:
docker exec jusprime_php_dev bash -c 'cd app && php bin/console cache:clear'
```

- URL: **http://localhost:8080** · login **farlei.rocha@gmail.com** / **Prime123!**
- ⚠️ **Gotcha:** o modal `#modalAlertaPonto` ("registrou sua entrada hoje?") intercepta cliques. É só
  fechá-lo (X) ao entrar; ele não atrapalha a leitura das telas.

Dados bons já prontos no dev:
- **Objeto 117** — 161 obrigações da carteira TOPLIFE I (a melhor tela para ver as colunas).
- **Objeto 296** — tem pagamentos (para o aviso de reconciliação e editar obrigação paga).
- **Carteira 1** = TOPLIFE I (já configurada: juros 1% / multa 2% / carência 30 / honorários 20%).

---

## 1. A linha da obrigação com as colunas do PDF  ✅ o coração da F4

Abra **http://localhost:8080/cobrancas/objetos/117** e role até "Dívida em aberto".

**O que conferir:**
- [ ] O cabeçalho da lista mostra **Original · Juros · Multa · Correção · Honorários · Total** (numa tela larga).
- [ ] Cada linha mostra os valores nas colunas certas, alinhados à direita.
- [ ] **A soma fecha:** Original + Juros + Multa + Correção + Honorários = **Total**. Ex.: uma linha de
      R$ 55,00 com juros R$ 49,15 → Total R$ 104,15. *(É o número do relatório da contabilidade — inclui
      honorários. Não confundir com o "Total em aberto" verde do topo, que é o saldo exigível, sem honorários
      e já descontado o que foi recebido. Passe o mouse no cabeçalho "Total" da coluna: o tooltip explica.)*
- [ ] **Floco de neve ❄** ao lado do Total nas obrigações importadas (congeladas). Tooltip: "Encargos
      congelados em … — não são recalculados automaticamente".

**Responsividade** (a página **nunca** deve rolar na horizontal):
- [ ] **Tela larga (≥1400px):** as 6 colunas aparecem.
- [ ] **Tela média (~1280px):** só **Original** e **Total**; os encargos viram um subtexto pequeno abaixo do
      Total (`J … · M … · C … · H …`).
- [ ] **Estreite bastante a janela:** só o Total, com a composição no subtexto. Sem barra de rolagem lateral.

---

## 2. Criar / editar obrigação com % ↔ R$  ✅ F4

Na mesma tela, clique **"Nova obrigação"** (ou o **⋯ → Editar** de uma linha).

**O que conferir no modal:**
- [ ] Seção **"Encargos reconhecidos"** com três pares lado a lado: **Juros (%) | Juros (R$)**, e o mesmo
      para Multa e Correção.
- [ ] **O espelho funciona nos dois sentidos:** digite `2` no campo **Multa (%)** de uma obrigação de
      R$ 1.000,00 → o **Multa (R$)** vira **20,00**. Digite `30,00` no R$ → o % vira **3,00**.
- [ ] Mudar o **Valor original** recalcula os **%** (o R$ é a verdade, não muda sozinho).
- [ ] O aviso: "Ao gravar um valor aqui a obrigação fica **congelada**".
- [ ] **No "Nova obrigação":** digite algo nos encargos, **feche sem salvar**, reabra → os encargos vêm
      **limpos** (não vaza o que você abandonou).
- [ ] **No "Editar":** reabra → os valores da obrigação **reaparecem** corretos (não foram limpos).

*(Opcional — salvar de verdade:* preencha e salve. A obrigação passa a mostrar o ❄ de congelada e os
valores que você digitou nas colunas.)*

---

## 3. Editar obrigação PAGA + aviso de reconciliação  ✅ F5

Abra **http://localhost:8080/cobrancas/objetos/296** (tem pagamentos).

- [ ] Numa obrigação que **já recebeu pagamento**, clique **⋯ → Editar**.
- [ ] Aparece um **aviso azul** no topo do modal: "Esta obrigação já recebeu pagamento. Se você alterar o
      valor, o valor recebido pode ficar sobrando ou faltando — ajuste em **Corrigir pagamento**".
- [ ] Numa obrigação **sem** pagamento, o mesmo modal **não** mostra esse aviso.
- [ ] *(Opcional)* aumente o Valor original de uma obrigação paga e salve → ela **volta a ter saldo** (aparece
      "falta R$ …") e o pagamento continua intacto. Reduzir abaixo do que já foi pago é **bloqueado** com
      mensagem clara.

---

## 4. Config da carteira (a cascata)  ✅ F2

Abra **http://localhost:8080/cobrancas/carteiras/1** (TOPLIFE I) → botão **"Editar configuração"**.

- [ ] Os campos de encargos aparecem preenchidos: **Juros a.m. 1,00% · Multa 2,00% · Correção 0 ·
      Carência dos honorários 30 dias · Honorários 20,00%**.
- [ ] O campo de taxa aceita pt-BR: digite `1,5` → salva como 1,5% (sem perder precisão).
- [ ] O texto de ajuda diz que essas taxas valem para **novas** obrigações (não recalculam o que já existe) e
      que carência vazia usa a tolerância de atraso.
- [ ] **"Nova carteira"** (na lista de carteiras) também tem os campos de encargos.

---

## 5. O cron de crescimento  ✅ F3  (terminal, não navegador)

```bash
# Simulação — NÃO grava nada, só mostra o que faria:
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/console app:cobranca:atualizar-encargos --dry-run'
```

**O que conferir no relatório:**
- [ ] **"Reduções bloqueadas" = 0** e **"Falhas" = 0**. *(Se aparecerem reduções, é sinal de config de taxa
      zerada — NÃO force `--permitir-reducao`; confira a carteira primeiro.)*
- [ ] "Puladas (congeladas)" reflete que as importadas não são tocadas.
- [ ] "Examinadas" é um número pequeno (só as **não** congeladas e exigíveis — hoje 23 no dev; as 3.271
      importadas estão congeladas e fora do cron).

Teste os freios de segurança:
```bash
# Data no passado é recusada (reduziria os encargos):
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/console app:cobranca:atualizar-encargos --hoje=2020-01-01 --dry-run'
# -> deve dar ERROR pedindo --forcar-retroativo
```

---

## 6. Prova de que nada quebrou (opcional, ~1 min)

```bash
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'
# -> OK (764 tests)
```

---

## Se algo parecer errado

- **Números não batem na tela** → confira contra o banco:
  `docker exec jusprime_db_dev psql -U symfony -d saas -c "SELECT id, valor_original, juros, multa, correcao, honorarios FROM cobranca_obrigacao WHERE caso_id IN (SELECT id FROM cobranca_caso WHERE objeto_id=117) LIMIT 5"`
- **CSS/layout velho** → `cache:clear` (passo 0) e recarregue com Ctrl+Shift+R.
- **500 numa tela** → veja o log: `docker exec jusprime_php_dev tail -50 app/var/log/dev.log`

Estado detalhado e go-live: `docs/gestao-cobrancas/GO_LIVE_ENCARGOS.md`.
