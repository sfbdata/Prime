<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

/**
 * Declara que um comando de console **não** lê, grava nem imprime dado pessoal de terceiro — e por
 * isso está dispensado da {@see \App\Cobranca\Service\Espelho\GuardaDeLogComPii}.
 *
 * 🔴 **Existe para acabar com o "não declarou nada".** A versão anterior da régua tentava *adivinhar*
 * quem lida com PII, procurando marcas (`sacado`, `Pessoa`, `telefone`…) no código. A terceira
 * revisão provou que a adivinhação era **circular**: apagando a guarda dos quatro comandos do espelho
 * e da reconciliação, sobravam **zero** marcas — porque a única marca que eles tinham era a string da
 * própria opção `--aceito-log-com-pii` ("A saída conterá CPF, e-mail e telefone"). Tirava a proteção,
 * sumia o motivo de exigi-la, e **o teste ficava verde justamente quando a proteção era removida**.
 *
 * > *"Teste que fica verde justamente quando a proteção é removida é pior que não ter teste — dá
 * > falsa segurança."* — dono, 13/08/2026.
 *
 * A régua nova não adivinha nada: **todo** comando de `src/Cobranca/Command/` declara uma das duas
 * interfaces, e quem não declarar nenhuma **quebra a suíte**. Um comando novo não consegue nascer
 * fora da régua, porque não consegue nascer sem decidir.
 *
 * ⚠️ **Declarar isto é uma afirmação sobre o comportamento, não um atalho para calar o teste.** Se o
 * comando passar a tocar nome, CPF, e-mail, telefone, endereço, ou a dupla unidade+boleto que
 * identifica o devedor, ele troca de interface e passa a consultar a guarda. O
 * `ComandosComPiiPassamPelaGuardaTest` ainda faz uma checagem de sanidade sobre quem declara isto —
 * e essa checagem **não** é circular, porque procura marcas de PII em código que, por declaração,
 * não deveria tê-las.
 */
interface NaoLidaComDadoPessoal
{
}
