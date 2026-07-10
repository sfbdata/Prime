<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\ImportarRelatorioInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Upload do relatório de inadimplência (.xlsx) para importação em massa numa Carteira (Etapa 8C). A
 * Carteira alvo vem da rota — não é campo. Validação do arquivo (tipo/tamanho) nos #[Assert] do DTO.
 * CSRF pelo token stateless `submit` (padrão do framework).
 */
final class ImportarRelatorioType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('arquivo', FileType::class, [
            'label' => 'Relatório de inadimplência (.xlsx)',
            'attr' => ['class' => 'form-control', 'accept' => '.xlsx,.xls'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ImportarRelatorioInput::class,
        ]);
    }
}
