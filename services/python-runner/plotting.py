import runpy
import sys
from pathlib import Path


def save_figures():
    pyplot = sys.modules.get('matplotlib.pyplot')
    if pyplot is None:
        return
    root = Path.cwd()
    index = 1
    for number in pyplot.get_fignums():
        figure = pyplot.figure(number)
        if not figure.axes:
            continue
        while (root / f'figure-{index}.png').exists():
            index += 1
        figure.savefig(root / f'figure-{index}.png', format='png', dpi=100)
        index += 1


def run(entry):
    sys.argv = [entry]
    try:
        runpy.run_path(entry, run_name='__main__')
    finally:
        save_figures()


if __name__ == '__main__':
    run(sys.argv[1])
