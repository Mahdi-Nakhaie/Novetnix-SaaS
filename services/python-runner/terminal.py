import importlib.metadata
import pathlib
import runpy
import shlex
import sys


def terminal(command):
    args = shlex.split(command)
    if not args:
        return
    verb, *args = args
    root = pathlib.Path.cwd().resolve()

    def path(value):
        candidate = (root / value).resolve()
        if not candidate.is_relative_to(root):
            raise ValueError('Only project-relative paths are allowed.')
        return candidate

    if verb in ('python', 'python3'):
        if len(args) == 2 and args[0] == '-c':
            exec(compile(args[1], '<terminal>', 'exec'), {'__name__': '__main__'})
        elif args and args[0].endswith('.py'):
            target = path(args[0])
            sys.argv = [str(target), *args[1:]]
            runpy.run_path(str(target), run_name='__main__')
        elif args == ['--version']:
            print(sys.version.split()[0])
        elif args == ['-m', 'pip', 'list']:
            terminal('pip list')
        else:
            raise ValueError('Use python main.py, python --version or python -c "code".')
    elif verb in ('ls', 'dir') and len(args) <= 1:
        for item in sorted(path(args[0] if args else '.').iterdir()):
            print(item.name + ('/' if item.is_dir() else ''))
    elif verb in ('cat', 'type') and len(args) == 1:
        target = path(args[0])
        if target.stat().st_size > 1048576:
            raise ValueError('File is too large.')
        print(target.read_text(encoding='utf-8'))
    elif verb == 'pwd' and not args:
        print('/workspace')
    elif verb == 'pip' and args == ['list']:
        for package in sorted(importlib.metadata.distributions(), key=lambda p: p.metadata['Name'].lower()):
            print(package.metadata['Name'] + '==' + package.version)
    elif verb == 'pip' and args and args[0] == 'install':
        raise ValueError('Packages are preinstalled; online installation is disabled in the isolated runtime. Use pip list.')
    else:
        raise ValueError('Unsupported command. Use help for available commands. Shell operators are not supported.')
