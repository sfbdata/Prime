<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da ANOTAÇÃO livre na linha do tempo do Caso de Cobrança (ajuste 2026-07). É a data_class do
 * formulário exibido no topo da aba Histórico, no espírito do campo de mensagem da timeline da Pasta.
 *
 * Só texto: o que é classificável (contato, acordo, pagamento) continua tendo o seu próprio formulário
 * com campos estruturados, porque relatório conta etiqueta e não lê texto corrido. A anotação existe
 * para o que sobra — o combinado, o contexto, o que o síndico falou — e nunca substitui um registro
 * estruturado.
 *
 * O caso alvo vem da rota; a resolução por id + tenant (guarda multi-tenant) é do controller/UseCase.
 */
final class RegistrarAnotacaoInput
{
    public ?int $casoId = null;

    #[Assert\NotBlank(message: 'Escreva a anotação antes de enviar.')]
    #[Assert\Length(
        max: 5000,
        maxMessage: 'A anotação pode ter no máximo {{ limit }} caracteres.',
    )]
    public ?string $texto = null;
}
