<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\FeriadoDoPontoInput;
use App\Ponto\Entity\Feriado;
use App\Ponto\Exception\ConfiguracaoDoPontoRecusadaException;
use App\Repository\LotacaoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cadastra um feriado pela aba Configurações (spec `docs/specs/ponto-desenho-0811.md`, E6: P71 com o desvio da E6,
 * P72 e D21). Data e nome são obrigatórios, com a frase do desenho. A abrangência vazia é "Todas as sedes" (vale para
 * todo o escritório); com uma lotação, o feriado vale só para quem tem aquela lotação (`FeriadosDaPessoa`). A lotação
 * tem de ser do escritório da sessão: a de outro escritório é "não encontrada" (404).
 */
final class CadastrarFeriadoDoPontoUseCase
{
    public const FALTA_DATA_OU_NOME = 'Informe a data e o nome do feriado.';
    public const NOME_MAXIMO = 255;

    public function __construct(
        private readonly LotacaoRepository $lotacoes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @throws ConfiguracaoDoPontoRecusadaException */
    public function executar(FeriadoDoPontoInput $dados, Tenant $escritorio): Feriado
    {
        $data = \DateTime::createFromFormat('!Y-m-d', $dados->data);
        if ($data === false || $data->format('Y-m-d') !== $dados->data || $dados->nome === '') {
            throw ConfiguracaoDoPontoRecusadaException::invalida([($dados->nome === '' ? 'nome' : 'data') => self::FALTA_DATA_OU_NOME]);
        }
        if (mb_strlen($dados->nome) > self::NOME_MAXIMO) {
            throw ConfiguracaoDoPontoRecusadaException::invalida(['nome' => 'O nome do feriado pode ter até 255 caracteres.']);
        }

        $feriado = (new Feriado())
            ->setTenant($escritorio)
            ->setNome($dados->nome)
            ->setData($data)
            ->setRecorrente($dados->recorrente)
            ->setLotacao($this->lotacao($dados->abrangencia, $escritorio));
        $this->em->persist($feriado);
        $this->em->flush();

        return $feriado;
    }

    private function lotacao(string $abrangencia, Tenant $escritorio): ?Lotacao
    {
        if ($abrangencia === '') {
            return null;
        }

        return (ctype_digit($abrangencia) ? $this->lotacoes->findOneBy(['id' => (int) $abrangencia, 'tenant' => $escritorio]) : null)
            ?? throw ConfiguracaoDoPontoRecusadaException::naoEncontrada('Lotação não encontrada.');
    }
}
