# ninespot
Launch a cloud machine on demand.

## How to use
Please configure the default project using `gcloud config` beforehand.

```sh
# Creates a disk image.  This should be persistent.
$ ninespot build --image=ubuntu
# Prepare a machine.
$ ninespot start --cpu=4
# Runs "ls -lA".
$ ninespot ls -lA
# Delete a machine.  Data on the disk should be kept.
$ ninespot stop
# Destroy a disk.
$ ninespot destroy
```

Run `ninespot --help` for more details.
