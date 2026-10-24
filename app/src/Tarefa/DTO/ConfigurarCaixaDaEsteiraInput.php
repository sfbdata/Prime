<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Entity\CaixaDaEsteira;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Campos do modal "Configurar caixa" (desenho 04 de 22/10, dc L133): NOME DA CAIXA, NOME DA ETAPA e AVISAR
 * QUANDO CHEGAR DEMANDA. Nome vazio volta ao padrão; por isso nenhum dos dois é obrigatório.
 */
final readonly class ConfigurarCaixaDaEsteiraInput
{
    /** @param list<int> $avisados ids de quem é avisado quando chega demanda */
    public function __construct(
        #[Assert\Length(max: CaixaDaEsteira::TAMANHO_MAXIMO_DO_NOME, maxMessage: 'O nome da caixa passa de {{ limit }} caracteres.')]
        public string $nomeDaCaixa,
        #[Assert\Length(max: CaixaDaEsteira::TAMANHO_MAXIMO_DO_NOME, maxMessage: 'O nome da etapa passa de {{ limit }} caracteres.')]
        public string $nomeDaEtapa,
        #[Assert\All([new Assert\Positive()])]
        public array $avisados = [],
    ) {
    }
}
